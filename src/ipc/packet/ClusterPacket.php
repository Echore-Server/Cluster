<?php

declare(strict_types=1);

namespace Echore\Cluster\ipc\packet;

use pocketmine\utils\BinaryStream;

abstract class ClusterPacket {

	final public function __construct() {
	}

	abstract public function getId(): string;

	abstract public function encode(BinaryStream $out): void;

	abstract public function decode(BinaryStream $in): void;
}
