<?php

declare(strict_types=1);

namespace Echore\Cluster\ipc\packet;

use pocketmine\utils\BinaryStream;

class ClusterPacketSessionStart extends ClusterPacket {

	public string $clusterIdentifier;

	public function getId(): string {
		return "cluster:session_start";
	}

	public function encode(BinaryStream $out): void {
		$out->putUnsignedVarInt(strlen($this->clusterIdentifier));
		$out->put($this->clusterIdentifier);
	}

	public function decode(BinaryStream $in): void {
		$this->clusterIdentifier = $in->get($in->getUnsignedVarInt());
	}
}
