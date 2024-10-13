<?php

declare(strict_types=1);

namespace Echore\Cluster\ipc\packet;

use pocketmine\utils\BinaryStream;

class ClusterPacketSessionClose extends ClusterPacket {

	public function getId(): string {
		return "cluster:session_close";
	}

	public function encode(BinaryStream $out): void {
	}

	public function decode(BinaryStream $in): void {
	}
}
