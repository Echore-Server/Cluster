<?php

declare(strict_types=1);

namespace Echore\Cluster\ipc;

use Echore\Cluster\ClusterConfiguration;
use Echore\Cluster\ClusterServerInfo;
use Echore\Cluster\ipc\packet\ClusterPacket;
use Echore\Cluster\ipc\packet\ClusterPacketPool;
use Echore\Cluster\ipc\packet\ClusterPacketSessionClose;
use Echore\Cluster\ipc\packet\ClusterPacketSessionHandshake;
use Echore\Cluster\ipc\packet\ClusterPacketSessionStart;
use pmmp\thread\ThreadSafeArray;
use pocketmine\snooze\SleeperHandlerEntry;
use pocketmine\thread\log\ThreadSafeLogger;
use pocketmine\thread\Thread;
use pocketmine\utils\BinaryStream;
use pocketmine\utils\Process;
use raklib\generic\SocketException;
use RuntimeException;
use Socket;
use Throwable;

class ClusterThread extends Thread {

	private int $nextPacketId;

	private ThreadSafeArray $receivedPackets;

	private ThreadSafeArray $sendQueue;

	private ThreadSafeArray $clientInfos;

	private ThreadSafeArray $connections;

	private bool $kill;

	private bool $forceKillEnabled;

	public function __construct(
		private readonly Socket               $socket,
		private readonly ClusterServerInfo    $info,
		private readonly ClusterConfiguration $config,
		private readonly ClusterPacketPool    $packetPool,
		private readonly ThreadSafeLogger     $logger,
		private readonly SleeperHandlerEntry  $sleeper
	) {
		$this->receivedPackets = new ThreadSafeArray();
		$this->nextPacketId = 0;
		$this->clientInfos = new ThreadSafeArray();
		$this->sendQueue = new ThreadSafeArray();
		$this->connections = new ThreadSafeArray();
		$this->kill = false;
		$this->forceKillEnabled = false;
	}

	/**
	 * @return ThreadSafeArray
	 */
	public function getConnections(): ThreadSafeArray {
		return $this->connections;
	}

	public function isClusterOnline(string $clusterIdentifier): bool {
		$info = $this->config->get($clusterIdentifier);

		if ($info === null) {
			return false;
		}

		return $this->connections->synchronized(function() use ($info): bool {
			return isset($this->connections["$info->ipcAddress:$info->ipcPort"]);
		});
	}

	/**
	 * @return PacketReceivingObject[]
	 */
	public function fetchPackets(): array {
		return $this->receivedPackets->synchronized(function(): array {
			return $this->receivedPackets->chunk($this->receivedPackets->count(), true);
		});
	}

	public function getThreadName(): string {
		return "Cluster";
	}

	protected function onRun(): void {
		gc_enable();
		$lastKeepAlive = [];
		$notifier = $this->sleeper->createNotifier();

		$this->helloToClusters();

		while (!$this->kill) {
			$this->wait();

			$this->flushQueuedPackets();

			if ($this->kill) {
				break;
			}

			if (!$this->readPackets($packets, $ip, $port, $disconnected)) {
				if ($disconnected && $ip !== null && $port !== null) {
					$this->logger->warning("Disconnecting $ip:$port (read error)");
					$this->disconnectClient($ip, $port);
				}
				continue;
			}

			// unconnected socket handling
			$unconnected = $this->connections->synchronized(function() use ($ip, $port): bool {
				return !isset($this->connections["$ip:$port"]);
			});
			if ($unconnected) {
				foreach ($packets as $pk) {
					if ($pk instanceof ClusterPacketSessionStart) {
						if (($info = $this->registerClientInfo($pk->clusterIdentifier, $ip, $port)) === null) {
							$this->logger->warning("Unknown cluster identifier: $pk->clusterIdentifier, dropping packets");
							break;
						}

						$this->logger->info("Received hello from cluster $info->identifier, handshaking");

						$handshake = new ClusterPacketSessionHandshake();
						$handshake->clusterIdentifier = $this->info->identifier;
						$this->sendPacket($info->identifier, $handshake);
					} elseif ($pk instanceof ClusterPacketSessionHandshake) {
						$this->logger->info("Received handshake from $pk->clusterIdentifier, starting session");

						if (($info = $this->registerClientInfo($pk->clusterIdentifier, $ip, $port)) === null) {
							$this->logger->warning("Unknown cluster identifier: $pk->clusterIdentifier, dropping packets");
							break;
						}
					} else {
						$class = $pk::class;
						$this->logger->warning("Unknown start packet: $class");
					}
				}

				continue;
			}

			$afterDisconnect = false;
			$this->receivedPackets->synchronized(function() use ($packets, $notifier, $ip, $port, &$afterDisconnect, &$lastKeepAlive): void {
				$clusterInfo = $this->getConnectedClusterInfo($ip, $port);

				if ($clusterInfo === null) {
					return;
				}

				foreach ($packets as $pk) {
					if ($pk instanceof ClusterPacketSessionClose) {
						$afterDisconnect = true;
					}
					// bullshit implementation, integrate to ClusterIPC I think
					$stream = new BinaryStream();
					$stream->putUnsignedVarInt(strlen($pk->getId()));
					$stream->put($pk->getId());
					$pk->encode($stream);
					$this->receivedPackets[$this->nextPacketId++] = new PacketReceivingObject($ip, $port, $clusterInfo, $stream->getBuffer());
				}

				$notifier->wakeupSleeper();
			});

			if ($afterDisconnect) {
				$this->logger->info("Session $ip:$port closed gracefully");
				$this->disconnectClient($ip, $port);
			}
		}

		// fixme: can't shutdown
		// BULLSHIT WINDOWS?

		@socket_close($this->socket);
		unset($notifier, $this->info, $this->logger, $this->config, $this->clientInfos, $this->connections, $this->nextPacketId, $this->receivedPackets, $this->sendQueue, $this->socket, $this->sleeper, $this->packetPool);

		if ($this->forceKillEnabled) {
			// FUCKING PATCH
			sleep(1);
			Process::kill(Process::pid());
		}
	}

	protected function helloToClusters(): void {
		$this->clientInfos->synchronized(function(): void {
			foreach ($this->config->getMap() as $info) {
				if ($info->identifier === $this->info->identifier) {
					continue;
				}
				$clientInfo = $this->clientInfos[$info->identifier] ?? null;

				if ($clientInfo === null) {
					$pk = new ClusterPacketSessionStart();
					$pk->clusterIdentifier = $this->info->identifier;
					$this->sendPacketTo($info->ipcAddress, $info->ipcPort, $pk);
					$this->logger->info("Sending hello to cluster $info->identifier");
				}
			}
		});
	}

	protected function sendPacketTo(string $ip, int $port, ClusterPacket $packet): void {
		$this->sendQueue->synchronized(function() use ($ip, $port, $packet): void {
			$arr = $this->sendQueue["$ip:$port"] ??= new ThreadSafeArray();
			$stream = new BinaryStream();
			$stream->putUnsignedVarInt(strlen($packet->getId()));
			$stream->put($packet->getId());
			$packet->encode($stream);
			$arr[] = $stream->getBuffer();
		});
	}

	protected function flushQueuedPackets(): void {
		$this->sendQueue->synchronized(function(): void {
			foreach ($this->sendQueue as $ipPort => $queue) {
				[$ip, $port] = explode(":", $ipPort);

				$port = (int) $port;

				/**
				 * @var ThreadSafeArray $queue
				 */

				$stream = new BinaryStream();
				while ($queue->count() > 0) {
					$pk = $queue->shift();
					$stream->put($pk);
				}

				if (@socket_sendto($this->socket, $stream->getBuffer(), strlen($stream->getBuffer()), 0, $ip, $port) === false) {
					$errno = socket_last_error($this->socket);
					throw new SocketException("Failed to send (errno $errno): " . trim(socket_strerror($errno)), $errno);
				}
			}

			$this->sendQueue = new ThreadSafeArray();
		});
	}

	protected function readPackets(&$packets, &$ip, &$port, &$disconnected): bool {
		$disconnected = false;
		if (@socket_recvfrom($this->socket, $buffer, 65536, 0, $ip, $port) === false) {
			$errno = socket_last_error($this->socket);
			if ($errno === SOCKET_EWOULDBLOCK) {
				return false;
			}
			if ($errno === 10054) {
				$disconnected = true;

				return false;
			}
			throw new SocketException("Failed to recv (errno $errno): " . trim(socket_strerror($errno)), $errno);
		}

		$stream = new BinaryStream($buffer);

		$packets = [];
		$e = null;
		while (!$stream->feof()) {
			$packetId = $stream->get($stream->getUnsignedVarInt());
			$packet = $this->packetPool->get($packetId);

			if ($packet === null) {
				$this->logger->error("Unknown packet delivered: $packetId");

				return false;
			}
			try {
				$packet->decode($stream);
			} catch (Throwable $e) {
				break;
			}

			$packets[] = $packet;
		}

		if (!$stream->feof()) {
			$this->logger->error("Exception occurred during decoding packets");
			if ($e === null) {
				throw new RuntimeException("This shouldn't be happen");
			}
			$this->logger->logException($e);
		}

		return true;
	}

	public function disconnectClient(string $ip, int $port): void {
		$this->logger->debug("Disconnected $ip:$port");
		$this->connections->synchronized(function() use ($ip, $port): void {
			unset($this->connections["$ip:$port"]);
		});
	}

	protected function registerClientInfo(string $clusterIdentifier, string $ip, int $port): ?ClusterServerInfo {
		$info = $this->config->get($clusterIdentifier);

		if ($info === null) {
			return null;
		}

		$this->clientInfos->synchronized(function() use ($info, $ip, $port): void {
			$this->clientInfos[$info->identifier] = serialize([$ip, $port]);
		});

		$this->connections->synchronized(function() use ($info, $ip, $port): void {
			$this->connections["$ip:$port"] = $info;
		});

		return $info;
	}

	public function sendPacket(string $targetCluster, ClusterPacket $packet): void {
		[$ip, $port] = $this->clientInfos->synchronized(function() use ($targetCluster): array {
			$clientInfo = $this->clientInfos[$targetCluster] ?? null;

			if ($clientInfo === null) {
				return [null, null];
			}

			return unserialize($clientInfo);
		});

		if ($ip === null) {
			return;
		}

		$this->sendPacketTo($ip, $port, $packet);
	}

	public function getConnectedClusterInfo(string $ip, int $port): ?ClusterServerInfo {
		return $this->connections->synchronized(function() use ($ip, $port): ?ClusterServerInfo {
			return $this->connections["$ip:$port"];
		});
	}

	public function kill(bool $forceKill): void {
		$this->logger->info("Sending bye to connected clusters");
		$this->broadcastPacket(new ClusterPacketSessionClose());
		$this->kill = true;
		$this->forceKillEnabled = $forceKill;
	}

	public function broadcastPacket(ClusterPacket $packet): void {
		$this->connections->synchronized(function() use ($packet): void {
			foreach ($this->connections as $info) {
				$this->sendPacketTo($info->ipcAddress, $info->ipcPort, $packet);
			}
		});
	}
}
