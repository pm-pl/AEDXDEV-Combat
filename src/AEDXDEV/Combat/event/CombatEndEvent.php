<?php

declare(strict_types=1);

namespace AEDXDEV\Combat\event;

use AEDXDEV\Combat\session\PlayerSession;

use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;

/*
 * Fired when the combat session between two players ends.
 */

class CombatEndEvent extends CombatEvent{

  public const CAUSE_TIME = 0;
  public const CAUSE_DIE = 1;
  public const CAUSE_KILL = 2;

  public function __construct(
    protected PlayerSession $player,
    protected ?PlayerSession $attacker, //killer
    protected ?PlayerSession $target, // last target the player attacked
    protected int $cause
  ){
    // NOPE
  }

  public function getPlayer(): PlayerSession{
    return $this->player;
  }

  public function getAttacker(): ?PlayerSession{
    return $this->attacker;
  }

  public function getTarget(): ?PlayerSession{
    return $this->target;
  }

  public function getCause(): int{
    return $this->cause;
  }
}