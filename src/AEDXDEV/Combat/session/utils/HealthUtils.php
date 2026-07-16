<?php
namespace AEDXDEV\Combat\session\utils;

use pocketmine\player\Player;

class HealthUtils {
  
  public const TYPE_NONE = 0;
  public const TYPE_NUMBER = 1;
  public const TYPE_BAR = 2;
  
  public static function formatHealth(Player $player, int $type): string{
    return match ($type) {
      self::TYPE_NUMBER => "§l§c❤ " . round($player->getHealth()),
      self::TYPE_BAR => str_repeat("§a█", round($player->getHealth() / 2)) . str_repeat("§c█", round(($player->getMaxHealth() - $player->getHealth()) / 2)),
      default => ""
    };
  }
}