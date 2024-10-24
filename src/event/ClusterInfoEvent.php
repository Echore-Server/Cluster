<?php

declare(strict_types=1);

namespace Echore\Cluster\event;

use Echore\Cluster\ClusterServerInfo;
use pocketmine\event\Event;

class ClusterInfoEvent extends Event {

	protected ClusterServerInfo $info;

	public function getInfo(): ClusterServerInfo {
		return $this->info;
	}
}
