<?php

declare(strict_types=1);

namespace AEDXDEV\Combat\event;

use pocketmine\event\Event;
use pocketmine\player\Player;

class CombatEvent extends Event{
 
  public const CAUSE_PLAYER_ATTACK = 0;
	public const CAUSE_ENTITY_ATTACK = 1;
	public const CAUSE_PROJECTILE = 2;
	public const CAUSE_FALL = 4;
	public const CAUSE_FIRE = 5;
	public const CAUSE_LAVA = 6;
	public const CAUSE_VOID = 7; 
	public const CAUSE_BLOCK_EXPLOSION = 9;
}
