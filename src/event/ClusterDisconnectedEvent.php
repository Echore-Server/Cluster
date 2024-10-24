<?php

declare(strict_types=1);

namespace Echore\Cluster\event;

use Echore\Cluster\ClusterServerInfo;

class ClusterDisconnectedEvent extends ClusterInfoEvent {

	public function __construct(ClusterServerInfo $info) {
		$this->info = $info;
	}
}
