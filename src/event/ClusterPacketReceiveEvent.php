<?php

declare(strict_types=1);

namespace Echore\Cluster\event;

use Echore\Cluster\ClusterServerInfo;
use Echore\Cluster\ipc\packet\ClusterPacket;
use pocketmine\event\Event;

class ClusterPacketReceiveEvent extends Event {

	protected ClusterServerInfo $from;

	protected ClusterPacket $packet;

	/**
	 * @param ClusterServerInfo $from
	 * @param ClusterPacket $packet
	 */
	public function __construct(ClusterServerInfo $from, ClusterPacket $packet) {
		$this->from = $from;
		$this->packet = $packet;
	}

	/**
	 * @return ClusterServerInfo
	 */
	public function getFrom(): ClusterServerInfo {
		return $this->from;
	}

	/**
	 * @return ClusterPacket
	 */
	public function getPacket(): ClusterPacket {
		return $this->packet;
	}

}
