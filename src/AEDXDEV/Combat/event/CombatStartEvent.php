<?php
declare(strict_types=1);

namespace AEDXDEV\Combat\event;

use AEDXDEV\Combat\Main;
use AEDXDEV\Combat\session\PlayerSession;


use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;

/**
 * Fired when a player initiates combat with another player.
 * Can be cancelled to block entering combat.
 * You may also tweak duration/hidePlayers before it propagates.
 */

class CombatStartEvent extends CombatEvent implements Cancellable{
  use CancellableTrait;

  public const POSSIBILITY_HIDE = -1;
  public const FORCE_HIDE = 0;
  public const FORCE_SHOW = 1;

  private int $hideMode = self::POSSIBILITY_HIDE; // force hide/show players
  
  public function __construct(
    protected PlayerSession $attacker,
    protected PlayerSession $victim,
    protected int $timer,
    protected int $cause,
    protected int $entityId
  ){
    // NOPE
  }

  public function getAttacker(): PlayerSession{
    return $this->attacker;
  }

  public function getVictim(): PlayerSession{
    return $this->victim;
  }

  public function isNonOpponents(): bool{
    $a = $this->attacker;
    $v = $this->victim;
    if ($a->getTargetPlayer()?->getName() == $v->getName() || $a->getAttackerPlayer()?->getName() == $v->getName())return false;
    return $a->isInCombat() || $v->isInCombat();
  }

  public function getHideMode(): int{
    return $this->hideMode;
  }

  public function setHideMode(int $value): void{
    $this->hideMode = $value;
  }

  public function getTimer(): int{
    return $this->timer;
  }

  public function setTimer(int $timer): void{
    $this->timer = max(0, $timer);
  }

  public function getCause(): int{
    return $this->cause;
  }
  
  public function getAttackerEntityId(): int{
    return $this->entityId;
  }
}