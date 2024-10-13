<?php

declare(strict_types=1);

namespace Echore\Cluster\event;

use Echore\Cluster\ClusterServerInfo;
use Echore\Cluster\ipc\ClusterIPC;
use Echore\Cluster\ipc\packet\ClusterPacket;
use pocketmine\event\Event;

class ClusterPacketReceiveEvent extends Event {

	protected ClusterIPC $ipc;

	protected ClusterServerInfo $from;

	protected ClusterPacket $packet;

	/**
	 * @param ClusterIPC $ipc
	 * @param ClusterServerInfo $from
	 * @param ClusterPacket $packet
	 */
	public function __construct(ClusterIPC $ipc, ClusterServerInfo $from, ClusterPacket $packet) {
		$this->ipc = $ipc;
		$this->from = $from;
		$this->packet = $packet;
	}

	/**
	 * @return ClusterIPC
	 */
	public function getIPC(): ClusterIPC {
		return $this->ipc;
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
