<?php

declare(strict_types=1);

namespace Echore\Cluster\ipc\packet;

use pmmp\thread\ThreadSafe;
use pmmp\thread\ThreadSafeArray;
use RuntimeException;

class ClusterPacketPool extends ThreadSafe {

	private ThreadSafeArray $map;

	public function __construct() {
		$this->map = new ThreadSafeArray();
	}

	public function register(ClusterPacket $packet): void {
		$this->map->synchronized(function() use ($packet): void {
			if (isset($this->map[$packet->getId()])) {
				throw new RuntimeException("Packet id {$packet->getId()} is already registered");
			}

			$this->map[$packet->getId()] = $packet::class;
		});
	}

	public function get(string $id): ?ClusterPacket {
		return $this->map->synchronized(function() use ($id): ?ClusterPacket {
			$class = $this->map[$id] ?? null;

			if ($class === null) {
				return null;
			}

			return new ($class)();
		});
	}
}
