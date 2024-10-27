<?php

declare(strict_types=1);

namespace Echore\Cluster\ipc;

use Echore\Cluster\ClusterConfiguration;
use Echore\Cluster\ClusterServerInfo;
use Echore\Cluster\event\ClusterDisconnectedEvent;
use Echore\Cluster\event\ClusterPacketReceiveEvent;
use Echore\Cluster\ipc\packet\ClusterPacket;
use Echore\Cluster\ipc\packet\ClusterPacketKeepAlive;
use Echore\Cluster\ipc\packet\ClusterPacketPool;
use Echore\Cluster\ipc\packet\ClusterPacketSessionClose;
use Echore\Cluster\ipc\packet\ClusterPacketSessionHandshake;
use Echore\Cluster\ipc\packet\ClusterPacketSessionStart;
use pocketmine\network\NetworkInterface;
use pocketmine\Server;
use pocketmine\snooze\SleeperHandler;
use pocketmine\snooze\SleeperHandlerEntry;
use pocketmine\thread\log\ThreadSafeLogger;
use pocketmine\utils\BinaryStream;
use RuntimeException;
use Throwable;

class ClusterIPC implements NetworkInterface {

	private ClusterThread $thread;

	private ClusterPacketPool $packetPool;

	private string $name;

	private int $lastKeepAlive;

	private int $lastCheckConnections;

	private array $lastKeepAliveResponse;

	private array $lastSeenConnections;

	private array $onlineClusters;

	private SleeperHandlerEntry $sleeperHandlerEntry;

	private bool $optionForceKill;

	public function __construct(
		private Server               $server,
		private ClusterServerInfo    $info,
		private ClusterConfiguration $config,
		private ThreadSafeLogger     $logger,
		SleeperHandler               $sleeper
	) {
		$this->packetPool = new ClusterPacketPool();
		$this->packetPool->register(new ClusterPacketSessionStart());
		$this->packetPool->register(new ClusterPacketSessionHandshake());
		$this->packetPool->register(new ClusterPacketSessionClose());
		$this->packetPool->register(new ClusterPacketKeepAlive());

		$this->onlineClusters = [];
		$this->lastSeenConnections = [];
		$this->lastKeepAlive = 0;
		$this->lastCheckConnections = 0;
		$this->optionForceKill = false;

		$socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

		if ($socket === false) {
			throw new RuntimeException("Failed to create socket" . socket_strerror(socket_last_error()));
		}

		if (!socket_bind($socket, $this->info->ipcAddress, $this->info->ipcPort)) {
			throw new RuntimeException("Failed to bind socket" . socket_strerror(socket_last_error()));
		}

		@socket_set_option($socket, SOL_SOCKET, SO_RCVBUF, 1024 * 1024 * 8);
		@socket_set_option($socket, SOL_SOCKET, SO_SNDBUF, 1024 * 1024 * 8);
		@socket_set_option($socket, SOL_SOCKET, SO_LINGER, ["l_onoff" => 1, "l_linger" => 1]);
		socket_set_nonblock($socket);

		$this->sleeperHandlerEntry = $sleeper->addNotifier(function(): void {
			foreach ($this->thread->fetchPackets() as $obj) {
				$stream = new BinaryStream($obj->packet);
				$packetId = $stream->get($stream->getUnsignedVarInt());
				$packet = $this->packetPool->get($packetId);

				if ($packet === null) {
					$this->logger->error("Unknown packet delivered from thread: $packetId");

					return;
				}
				try {
					$packet->decode($stream);
				} catch (Throwable $e) {
					continue;
				}

				if ($packet instanceof ClusterPacketKeepAlive) {
					if ($packet->response) {
						$this->lastKeepAliveResponse[$obj->clusterInfo->identifier] = time();
					} else {
						$resPk = new ClusterPacketKeepAlive();
						$resPk->response = true;
						$this->sendPacket($obj->clusterInfo->identifier, $resPk);
					}
				}
				//$this->logger->debug("Received packet from {$obj->clusterInfo->identifier}, packet: " . $packet->getId());

				$ev = new ClusterPacketReceiveEvent($this, $obj->clusterInfo, $packet);
				$ev->call();
			}
		});

		$this->setName("Cluster");
		$this->thread = new ClusterThread(
			$socket,
			$this->info,
			$this->config,
			$this->packetPool,
			$this->logger,
			$this->sleeperHandlerEntry
		);
	}

	public function sendPacket(string $toCluster, ClusterPacket $packet): void {
		$this->thread->sendPacket($toCluster, $packet);
	}

	public function setName(string $name): void {
		$this->name = $name;
	}

	public function isOptionForceKill(): bool {
		return $this->optionForceKill;
	}

	public function setOptionForceKill(bool $optionForceKill): void {
		$this->optionForceKill = $optionForceKill;
	}

	/**
	 * @return ClusterPacketPool
	 */
	public function getPacketPool(): ClusterPacketPool {
		return $this->packetPool;
	}

	public function isClusterOnline(string $cluster): bool {
		return $this->thread->isClusterOnline($cluster);
	}

	/**
	 * @return array<string, ClusterServerInfo>
	 */
	public function getOnlineClusters(): array {
		return $this->onlineClusters;
	}

	public function start(): void {
		$this->thread->start(1);
	}

	public function tick(): void {
		if (time() - $this->lastCheckConnections > 5) {
			$this->thread->getConnections()->synchronized(function(): void {
				$seenConnections = iterator_to_array($this->thread->getConnections()->getIterator());
				$disconnectedClients = array_diff_key($this->lastSeenConnections, $seenConnections);
				$newConnectedClients = array_diff_key($seenConnections, $this->lastSeenConnections);

				foreach ($disconnectedClients as $info) {
					/**
					 * @var ClusterServerInfo $info
					 */

					$ev = new ClusterDisconnectedEvent($info);
					$ev->call();

					unset($this->lastKeepAliveResponse[$info->identifier]);

					$this->logger->info("Cluster $info->identifier is now offline");
				}

				foreach ($newConnectedClients as $info) {
					/**
					 * @var ClusterServerInfo $info
					 */

					$ev = new ClusterDisconnectedEvent($info);
					$ev->call();

					$this->lastKeepAliveResponse[$info->identifier] = time();

					$this->logger->info("Cluster $info->identifier is now online");
				}

				$this->lastSeenConnections = $seenConnections;
				$this->onlineClusters = $seenConnections;
			});
		}
		if (time() - $this->lastKeepAlive > 15) {
			$this->lastKeepAlive = time();

			$this->thread->getConnections()->synchronized(function(): void {
				foreach ($this->thread->getConnections() as $addr => $info) {
					/**
					 * @var ClusterServerInfo $info
					 */
					$clusterIdentifier = $info->identifier;
					$lastResponseTime = $this->lastKeepAliveResponse[$clusterIdentifier] ?? 0;
					if (time() - $lastResponseTime > 15 + 5) {
						$this->logger->info("Disconnecting $info->ipcAddress:$info->ipcPort (no response for 5 seconds)");
						$this->thread->disconnectClient($info->ipcAddress, $info->ipcPort);
						unset($this->lastKeepAliveResponse[$clusterIdentifier]);
					}
				}
			});

			$pk = new ClusterPacketKeepAlive();
			$pk->response = false;
			$this->thread->broadcastPacket($pk);
		}

		$this->thread->notify();
	}

	public function broadcastPacket(ClusterPacket $packet): void {
		$this->thread->broadcastPacket($packet);
	}

	public function shutdown(): void {
		$this->server->getTickSleeper()->removeNotifier($this->sleeperHandlerEntry->getNotifierId());
		$this->thread->kill($this->optionForceKill);
		$this->thread->notify();
		unset($this->thread);
	}
}
