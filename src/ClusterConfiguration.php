<?php

declare(strict_types=1);

namespace Echore\Cluster;

use pmmp\thread\ThreadSafe;
use pmmp\thread\ThreadSafeArray;

class ClusterConfiguration extends ThreadSafe {

	/**
	 * @var array<string, ClusterServerInfo>
	 */
	private ThreadSafeArray $map;

	public function __construct(array $map) {
		$this->map = ThreadSafeArray::fromArray($map);
	}

	/**
	 * @return ThreadSafeArray
	 */
	public function getMap(): ThreadSafeArray {
		return $this->map;
	}
	
	public function get(string $identifier): ?ClusterServerInfo {
		return $this->map[$identifier] ?? null;
	}
}
