<?php

declare(strict_types=1);

namespace AEDXDEV\Combat\session;

use AEDXDEV\Combat\session\utils\HealthUtils;

use pocketmine\player\Player;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\StringMetadataProperty;
use pocketmine\Server;
use function strtolower;

class SessionManager {
	
	public function __construct() {}
	/** @var PlayerSession[] */
  private static array $sessions = [];
  private static array $lastScoreTags = [];

	public function getSessions(): array{
		return self::$sessions;
	}

	public function hasSession(Player|string $player): bool{
		$name = $player instanceof Player ? $player->getName() : $player;
		return isset(self::$sessions[strtolower($name)]);
	}

	public function get(Player|string $player): ?PlayerSession{
		$name = $player instanceof Player ? $player->getName() : $player;
		return self::$sessions[strtolower($name)] ?? null;
	}

	public function createSession(Player $player): PlayerSession{
		return self::$sessions[strtolower($player->getName())] ??= new PlayerSession($player->getName());
	}

	public function removeSession(Player|string $player): void{
	  $name = $player instanceof Player ? $player->getName() : $player;
		self::get($name)?->save();
		unset(self::$sessions[strtolower($name)]);
		unset(self::$lastScoreTags[strtolower($name)]);
	}
	
	public function close(): void{
		array_walk(self::$sessions, fn($session) => $this->removeSession($session->getName()));
	}
	
	public function sendScoreTag(Player $player, array $viewers, string $text): void{
    if ((self::$lastScoreTags[$key = strtolower($player->getName())] ?? null) === $text)return;
    self::$lastScoreTags[$key] = $text;
    $player->sendData($viewers, [EntityMetadataProperties::SCORE_TAG => new StringMetadataProperty($text)]);
  }

  public function clearScoreTag(Player $player): void{
    $this->sendScoreTag($player, Server::getInstance()->getOnlinePlayers(), "");
  }
	
	public function tickAll(): void{
		array_walk(self::$sessions, fn($session) => $session->tick());
	}
}
