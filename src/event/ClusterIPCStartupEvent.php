<?php

declare(strict_types=1);

namespace Echore\Cluster\event;

use Echore\Cluster\ipc\ClusterIPC;
use pocketmine\event\Event;

class ClusterIPCStartupEvent extends Event {

	public function __construct(
		protected ClusterIPC $ipc
	) {
	}

	/**
	 * @return ClusterIPC
	 */
	public function getIPC(): ClusterIPC {
		return $this->ipc;
	}
}
