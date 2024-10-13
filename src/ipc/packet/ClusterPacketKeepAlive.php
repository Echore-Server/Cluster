<?php

declare(strict_types=1);

namespace Echore\Cluster\ipc\packet;

use pocketmine\utils\BinaryStream;

class ClusterPacketKeepAlive extends ClusterPacket {

	public bool $response;

	public function getId(): string {
		return "cluster:keep_alive";
	}

	public function encode(BinaryStream $out): void {
		$out->putBool($this->response);
	}

	public function decode(BinaryStream $in): void {
		$this->response = $in->getBool();
	}
}
