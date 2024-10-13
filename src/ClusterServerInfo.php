<?php

declare(strict_types=1);

namespace Echore\Cluster;

use pmmp\thread\ThreadSafe;

class ClusterServerInfo extends ThreadSafe {

	public function __construct(
		public readonly string $identifier,
		public readonly string $address,
		public readonly int    $port,
		public readonly string $ipcAddress,
		public readonly int    $ipcPort
	) {
	}

}
