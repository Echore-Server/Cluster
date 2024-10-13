<?php

declare(strict_types=1);

namespace Echore\Cluster\ipc;

use Echore\Cluster\ClusterConfiguration;
use Echore\Cluster\ClusterServerInfo;
use Echore\Cluster\event\ClusterPacketReceiveEvent;
use Echore\Cluster\ipc\packet\ClusterPacket;
use Echore\Cluster\ipc\packet\ClusterPacketKeepAlive;
use Echore\Cluster\ipc\packet\ClusterPacketPool;
use Echore\Cluster\ipc\packet\ClusterPacketSessionClose;
use Echore\Cluster\ipc\packet\ClusterPacketSessionHandshake;
use Echore\Cluster\ipc\packet\ClusterPacketSessionStart;
use pocketmine\network\NetworkInterface;
use pocketmine\snooze\SleeperHandler;
use pocketmine\thread\log\ThreadSafeLogger;
use RuntimeException;
use Socket;

class ClusterIPC implements NetworkInterface {

	private ClusterThread $thread;

	private ClusterPacketPool $packetPool;

	private Socket $socket;

	private string $name;

	private int $lastKeepAlive;

	private array $lastKeepAliveResponse;

	public function __construct(
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

		$this->lastKeepAlive = 0;

		$socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

		if ($socket === false) {
			throw new RuntimeException("Failed to create socket" . socket_strerror(socket_last_error()));
		}

		$this->socket = $socket;

		if (!socket_bind($socket, $this->info->ipcAddress, $this->info->ipcPort)) {
			throw new RuntimeException("Failed to bind socket" . socket_strerror(socket_last_error()));
		}

		var_dump($this->info);
		@socket_set_option($this->socket, SOL_SOCKET, SO_RCVBUF, 1024 * 1024 * 8);
		@socket_set_option($this->socket, SOL_SOCKET, SO_SNDBUF, 1024 * 1024 * 8);
		@socket_set_option($this->socket, SOL_SOCKET, SO_KEEPALIVE, 1);
		@socket_set_option($this->socket, SOL_SOCKET, SO_LINGER, ["l_onoff" => 1, "l_linger" => 20]);
		socket_set_nonblock($socket);

		$entry = $sleeper->addNotifier(function(): void {
			foreach ($this->thread->fetchPackets() as $obj) {
				$packet = $obj->packet;
				if ($packet instanceof ClusterPacketKeepAlive) {
					if ($packet->response) {
						$this->lastKeepAliveResponse[$obj->clusterInfo->identifier] = time();
					} else {
						$resPk = new ClusterPacketKeepAlive();
						$resPk->response = true;
						$this->sendPacket($obj->clusterInfo->identifier, $resPk);
					}
				}
				$this->logger->debug("Received packet from {$obj->clusterInfo->identifier}, packet: " . $obj->packet::class);

				$ev = new ClusterPacketReceiveEvent($this, $obj->clusterInfo, $obj->packet);
				$ev->call();
			}
		});

		$this->name = "";
		$this->thread = new ClusterThread(
			$socket,
			$this->info,
			$this->config,
			$this->packetPool,
			$this->logger,
			$entry
		);
	}

	public function sendPacket(string $toCluster, ClusterPacket $packet): void {
		$this->thread->sendPacket($toCluster, $packet);
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

	public function start(): void {
		$this->thread->start();
	}

	public function setName(string $name): void {
		$this->name = $name;
	}

	public function tick(): void {
		if (time() - $this->lastKeepAlive > 15) {
			$this->lastKeepAlive = time();

			foreach ($this->thread->getConnections() as $addr => $info) {
				/**
				 * @var ClusterServerInfo $info
				 */
				$clusterIdentifier = $info->identifier;
				$lastResponseTime = $this->lastKeepAliveResponse[$clusterIdentifier] ?? time();
				if (time() - $lastResponseTime > 15 + 5) {
					$this->logger->info("Disconnecting $info->ipcAddress:$info->ipcPort (no response for 5 seconds)");
					$this->thread->disconnectClient($info->ipcAddress, $info->ipcPort);
					unset($this->lastKeepAliveResponse[$clusterIdentifier]);
				}
			}

			$pk = new ClusterPacketKeepAlive();
			$pk->response = false;
			$this->thread->broadcastPacket($pk);
		}
	}

	public function shutdown(): void {
		$this->thread->kill();
		$this->thread->notify();
	}
}
