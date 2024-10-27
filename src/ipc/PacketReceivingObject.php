<?php

declare(strict_types=1);

namespace Echore\Cluster\ipc;

use Echore\Cluster\ClusterServerInfo;
use pmmp\thread\ThreadSafe;

class PacketReceivingObject extends ThreadSafe {

	public string $ip;

	public int $port;

	public ClusterServerInfo $clusterInfo;

	public string $packet;

	/**
	 * @param string $ip
	 * @param int $port
	 * @param ClusterServerInfo $clusterInfo
	 * @param string $packet
	 */
	public function __construct(string $ip, int $port, ClusterServerInfo $clusterInfo, string $packet) {
		$this->ip = $ip;
		$this->port = $port;
		$this->clusterInfo = $clusterInfo;
		$this->packet = $packet;
	}


}
