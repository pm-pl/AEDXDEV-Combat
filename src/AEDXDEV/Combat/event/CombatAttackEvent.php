<?php
declare(strict_types=1);

namespace AEDXDEV\Combat\event;

use AEDXDEV\Combat\session\PlayerSession;

use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;

/**
 * Fired when a player attacks another player while (possibly) in combat.
 * Can be cancelled to block the hit (e.g., BlockNonOpponents logic).
 */

class CombatAttackEvent extends CombatEvent implements Cancellable{
  use CancellableTrait;
  
  public function __construct(
    protected PlayerSession $attacker,
    protected PlayerSession $victim,
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

  public function getCause(): int{
    return $this->cause;
  }
  
  public function getAttackerEntityId(): int{
    return $this->entityId;
  }
}