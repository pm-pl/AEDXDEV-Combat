<?php

declare(strict_types=1);

namespace AEDXDEV\Combat\provider;

use AEDXDEV\Combat\Main;

use pocketmine\player\Player;

abstract class Provider {

  protected mixed $database;

	public function __construct(
		protected Main $plugin
	) {
	  $this->load();
	}

	abstract public function load(): void;

	abstract public function loadPlayerData(Player $player): void;

  abstract public function updatePlayerData(Player $player): void;

  abstract public function close(): void;
}