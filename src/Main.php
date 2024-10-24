<?php

declare(strict_types=1);

namespace Echore\Cluster;

use Echore\Cluster\ipc\ClusterIPC;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\SingletonTrait;
use RuntimeException;
use Symfony\Component\Filesystem\Path;

class Main extends PluginBase {
	use SingletonTrait;

	private ClusterConfiguration $configuration;

	private ClusterServerInfo $info;

	private ClusterIPC $ipc;

	/**
	 * @return ClusterIPC
	 */
	public function getIPC(): ClusterIPC {
		return $this->ipc;
	}

	protected function onLoad(): void {
		self::setInstance($this);
		$configDefPath = Path::join($this->getDataFolder(), "path.txt");
		$clusterPath = Path::join($this->getDataFolder(), "cluster.txt");
		if (!file_exists($configDefPath)) {
			throw new RuntimeException("Please define config path at path.txt");
		}
		if (!file_exists($configDefPath)) {
			throw new RuntimeException("Please set cluster identifier at cluster.txt");
		}

		$configPath = file_get_contents($configDefPath);
		$cluster = file_get_contents($clusterPath);

		if (!file_exists($configPath)) {
			throw new RuntimeException("Defined config file $configPath not found");
		}

		$config = new Config($configPath, Config::JSON, []);

		$map = [];
		$matched = false;

		foreach ($config->getAll() as $clusterId => $infoJson) {
			$map[$clusterId] = $info = new ClusterServerInfo(
				$infoJson["identifier"],
				$infoJson["address"],
				$infoJson["port"],
				$infoJson["ipc_address"],
				$infoJson["ipc_port"]
			);

			if ($cluster === $info->identifier) {
				$this->info = $info;
				$matched = true;
			}
		}

		if (!$matched) {
			throw new RuntimeException("No cluster server info provided for this server");
		}

		$this->configuration = new ClusterConfiguration($map);
	}

	protected function onEnable(): void {
		$this->getLogger()->info("Starting cluster IPC at {$this->info->ipcAddress}:{$this->info->ipcPort}");
		$this->ipc = new ClusterIPC(
			$this->getServer(),
			$this->info,
			$this->configuration,
			$this->getServer()->getLogger(),
			$this->getServer()->getTickSleeper()
		);

		$this->getServer()->getNetwork()->registerInterface($this->ipc);
	}
}
