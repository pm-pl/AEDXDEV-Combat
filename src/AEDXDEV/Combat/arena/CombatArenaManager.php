<?php

declare(strict_types=1);

namespace AEDXDEV\Combat\arena;

use pocketmine\player\Player;

class CombatArenaManager {
  /** @var callable[] */
  private array $conditions = [];

  public function __construct() {}

  public function addCondition(callable $condition): void{
    $this->conditions[] = $condition;
  }

  public function canStartCombat(Player $player): bool{
    foreach ($this->conditions as $cond) {
      if ($cond($player) === true)return true;
    }
    return empty($this->conditions) ? true : false;
  }
}