<?php
declare(strict_types=1);

namespace AEDXDEV\Combat\session;

use pocketmine\player\Player;
use pocketmine\scheduler\ClosureTask;
use pocketmine\Server;

use AEDXDEV\Combat\Main;
use AEDXDEV\Combat\event\CombatEvent;
use AEDXDEV\Combat\event\CombatStartEvent;
use AEDXDEV\Combat\event\CombatAttackEvent;
use AEDXDEV\Combat\event\CombatEndEvent;
use AEDXDEV\Combat\session\utils\HealthUtils;

final class PlayerSession {

  public const POSSIBILITY_HIDE = -1;
  public const FORCE_HIDE = 0;
  public const FORCE_SHOW = 1;

  private bool $loaded = false;

  // Combat
  private bool $inCombat = false;
  private int $hideMode = CombatStartEvent::POSSIBILITY_HIDE;
  private float $combatEndsAt = 0.0;
  private int $timer = 0;
  private int $combatDuration;
  private int $lastCause = CombatEvent::CAUSE_PLAYER_ATTACK;

  // Links
  private ?string $lastAttackerName = null;
  private float $lastAttackedAt = 0.0;

  private ?string $lastTargetName = null;
  private float $lastTargetedAt = 0.0;
  
  private ?string $lastKiller = null;

  // CPS + Visibility
  private array $clickTimes = [];
  private array $hiddenPlayers = [];

  // Settings
  private bool $cpsPopup = true;
  private bool $cpsDisplay = true;
  private bool $combatSounds = true;
  private bool $projectilesSound = true;
  private bool $hideNonOpponents = false;
  private bool $sendMessages = true;
  private int $healthType = HealthUtils::TYPE_NONE;

  public function __construct(
    private string $name
  ) {
    Main::getInstance()->getProvider()->loadPlayerData($this->getPlayer());
    $this->combatDuration = (int) Main::getInstance()->getConfig()->get("Timer", 10);
  }

  public function getName(): string{
    return $this->name;
  }

  public function tick(): void{
    $player = $this->getPlayer();
    if (!$player instanceof Player) {
      Main::getInstance()->getSessionManager()->removeSession($this->name);
      return;
    }
    $now = microtime(true);
    $oldCps = count($this->clickTimes);
    if (!empty($this->clickTimes)) {
      $this->clickTimes = array_values(array_filter($this->clickTimes, static fn(float $t) => ($now - $t) <= 1.0));
    }
    if (count($this->clickTimes) !== $oldCps)$this->updateScoreTag();
    if ($this->inCombat) {
      //$this->applyHideNonOpponents();
      $remain = max(0.0, $this->combatEndsAt - $now);
      $this->timer = (int) ceil($remain);
      if ($remain <= 0.0) {
        $this->endCombat(CombatEndEvent::CAUSE_TIME);
        $linkWin = (float) Main::getInstance()->getConfig()->get("KillCreditWindow", 10.0);
        if ($this->lastAttackerName !== null && ($now - $this->lastAttackedAt) >= $linkWin)$this->lastAttackerName = null;
        if ($this->lastTargetName !== null && ($now - $this->lastTargetedAt) >= $linkWin)$this->lastTargetName = null;
        $this->clearHidden();
        $this->updateScoreTag();
      }
    }
  }

  public function isInCombat(): bool{
    return $this->inCombat;
  }

  /*public function canAttack(PlayerSession $target): bool{
    if (!$this->inCombat || !Main::getInstance()->getConfig()->get("BlockNonOpponents", false))return true;
    $name = $target->getName();
    return $this->lastTargetName == $name || $this->lastAttackerName == $name;
  }*/

  public function attackPlayer(PlayerSession $target, int $cause = CombatEvent::CAUSE_PLAYER_ATTACK): void{
    $this->attackPlayerSilent($target, $cause);
    $target->gotAttack($this);
    $this->updateScoreTag();
    $target->updateScoreTag();
    if ($this->startOrExtendCombat()) {
      if ($this->isSendMessages()) {
        $this->getPlayer()?->sendMessage(Main::getInstance()->getMessage("Start", ["player" => $target->getName()]));
      }
      if ($this->isCombatSounds()) {
        Main::getInstance()->playSound($this->getPlayer(), "Start");
      }
    }
  }

  public function attackPlayerSilent(PlayerSession $target, int $cause = CombatEvent::CAUSE_PLAYER_ATTACK): void{
    $this->lastTargetName = $target->getName();
    $this->lastTargetedAt = microtime(true);
    $this->lastCause = $cause;
    if (($p = $target->getPlayer()) instanceof Player)$this->showPlayer($p);
  }

  public function gotAttack(PlayerSession $attacker, int $cause = CombatEvent::CAUSE_PLAYER_ATTACK): void{
    $this->gotAttackSilent($attacker, $cause);
    $this->updateScoreTag();
    if ($this->startOrExtendCombat()) {
      if ($this->isSendMessages()) {
        $this->getPlayer()?->sendMessage(Main::getInstance()->getMessage("Start", ["player" => $attacker->getName()]));
      }
      if ($this->isCombatSounds()) {
        Main::getInstance()->playSound($this->getPlayer(), "Start");
      }
    }
  }
  
  public function gotAttackSilent(PlayerSession $attacker, int $cause = CombatEvent::CAUSE_PLAYER_ATTACK): void{
    $this->lastAttackerName = $attacker->getName();
    $this->lastAttackedAt = microtime(true);
    $this->lastCause = $cause;
    if (($p = $attacker->getPlayer()) instanceof Player)$this->showPlayer($p);
  }

  private function startOrExtendCombat(): bool{
    $inCombat = $this->inCombat;
    $now = microtime(true);
    if (!$inCombat)$this->applyHideNonOpponents();
    $this->inCombat = true;
    $this->combatEndsAt = max($this->combatEndsAt, $now + $this->combatDuration);
    $this->timer = (int) ceil($this->combatEndsAt - $now);
    return !$inCombat;
  }

  public function onDie(): void{
    if (!($player = $this->getPlayer()) instanceof Player)return;
    if ($this->isCombatSounds()) {
      Main::getInstance()->playSound($player, "Death");
    }
    $this->onDieSilent();
    $killer = $this->getKiller();
    if ($killer instanceof PlayerSession) {
      if (Main::getInstance()->getConfig()->get("DeathMessages", false)) {
        if ($killer->isSendMessages()) {
          $killer->getPlayer()->sendMessage(Main::getInstance()->getMessage("Kill", ["player" => $player->getName()]));
        }
        if ($this->isSendMessages()) {
          $player->sendMessage(Main::getInstance()->getMessage("Death", ["player" => $killer->getName()]));
        }
      }
      $killer->onKillPlayer();
    }
    $this->endCombat(CombatEndEvent::CAUSE_DIE, $killer?->getName());
  }

  public function onDieSilent(): void{
    if (!($player = $this->getPlayer()) instanceof Player)return;
    $config = Main::getInstance()->getConfig();
    $cache = Main::getInstance()->getBurnCache();
    $killer = null;
    $owner = $cache->resolveOwnerNear($player->getPosition(), "lava", $config->get("KillCreditTtl", 10.0), 4.0) ?? $cache->resolveOwnerNear($player->getPosition(), "fire", $config->get("KillCreditTtl", 10.0), 1.0);
    if ($owner !== null) {
      $killer = Main::getInstance()->getSessionManager()->get($owner);
    }
    if ($killer == null) {
      if ($this->lastAttackerName !== null && (microtime(true) - $this->lastAttackedAt) <= (float) $config->get("KillCreditWindow", 10.0)) {
        $killer = $this->getAttackerPlayer();
      }
    }
    if ($killer instanceof PlayerSession) {
      $this->lastKiller = $killer->getName();
    }
    //$this->resetCombat();
    Main::getInstance()->getScheduler()->scheduleDelayedTask(new ClosureTask(fn () => $this->inCombat ? $this->endCombat(CombatEndEvent::CAUSE_DIE, $this->lastKiller) : null), 1);
  }

  public function onKillPlayer(): void{
    $this->endCombat(CombatEndEvent::CAUSE_KILL, $this->lastTargetName);
  }

  public function endCombat(int $cause, ?string $name = null): void{
    if (!$this->inCombat)return;
    (new CombatEndEvent($this, $this->getAttackerPlayer(), $this->getTargetPlayer(), $cause))->call();
    switch ($cause) {
      case CombatEndEvent::CAUSE_DIE:
        if (Main::getInstance()->getConfig()->get("DeathMessages", false) && $this->isSendMessages()) {
          $this->getPlayer()->sendMessage(Main::getInstance()->getMessage("Death", ["player" => $name]));
        }
        if ($this->isCombatSounds()) {
          Main::getInstance()->playSound($this->getPlayer(), "Death");
        }
      break;
      case CombatEndEvent::CAUSE_KILL:
        if (Main::getInstance()->getConfig()->get("DeathMessages", false) && $this->isSendMessages()) {
          $this->getPlayer()->sendMessage(Main::getInstance()->getMessage("Kill", ["player" => $name]));
        }
        if ($this->isCombatSounds()) {
          Main::getInstance()->playSound($this->getPlayer(), "Kill");
        }
      break;
      default:
        if ($this->isSendMessages()) {
          $this->getPlayer()?->sendMessage(Main::getInstance()->getMessage("End"));
        }
        if ($this->isCombatSounds()) {
          Main::getInstance()->playSound($this->getPlayer(), "End");
        }
      break;
    }
    $this->resetCombat();
  }
  
  public function getLastDamageCause(): int{
    return $this->lastCause;
  }
  
  public function resetCombat(): void{
    $this->inCombat = false;
    $this->combatEndsAt = 0.0;
    $this->timer = 0;
    $this->lastAttackerName = null;
    $this->lastTargetName = null;
    $this->lastKiller = null;
    $this->clickTimes = [];
    $this->clearHidden();
    $this->updateScoreTag();
  }

  public function getAttackerPlayer(): ?PlayerSession{
    if ($this->lastAttackerName == null)return null;
    return Main::getInstance()->getSessionManager()->get($this->lastAttackerName) ?? null;
  }

  public function getTargetPlayer(): ?PlayerSession{
    if ($this->lastTargetName == null)return null;
    return Main::getInstance()->getSessionManager()->get($this->lastTargetName) ?? null;
  }

  public function getKiller(): ?PlayerSession{
    if ($this->lastKiller == null)return null;
    return Main::getInstance()->getSessionManager()->get($this->lastKiller) ?? null;
  }

  public function getCombatTimer(): int{
    return $this->timer;
  }

  public function addClick(): void{
    $now = microtime(true);
    $this->clickTimes[] = $now;
    $this->clickTimes = array_values(array_filter($this->clickTimes, static fn(float $t) => ($now - $t) <= 1.0));
    $this->updateScoreTag();
    if ($this->cpsPopup){
      $this->getPlayer()?->sendActionBarMessage("§eCPS: §f" . count($this->clickTimes));
    }
  }

  public function getCps(): int{
    return count($this->clickTimes);
  }
  
  private function updateScoreTag(): void{
    $me = $this->getPlayer();
    if ($me === null)return;
    $sManager = Main::getInstance()->getSessionManager();
    if (!$this->inCombat){
      $sManager->clearScoreTag($me);
      return;
    }
    $viewers = [];
    if (($t = $this->getTargetPlayer()) !== null) $viewers[$t->getName()] = $t->getPlayer();
    if (($a = $this->getAttackerPlayer()) !== null) $viewers[$a->getName()] = $ap = $a->getPlayer();
    if (empty($viewers))return;
    foreach ($viewers as $viewer) {
      $vs = $sManager->get($viewer);
      if ($vs === null)continue;
      $parts = [];
      if ($vs->isHealthDisplay()) {
        $parts[] = HealthUtils::formatHealth($me, $vs->getHealthType());
      }
      if ($vs->isCpsDisplay()) {
        $cps = $this->getCps();
        /*if ($cps > 0)*/$parts[] = "§f{$cps} §eCPS";
      }
      $sManager->sendScoreTag($me, [$viewer], ($vs->getHealthType() == HealthUtils::TYPE_NUMBER ? implode(" §r§7|§r ", $parts) : implode("\n", $parts)));
    }
  }

  public function setHideMode(int $value): void{
    $this->hideMode = $value;
  }

  public function canApplyHide(): bool{
    if ((!$this->hideNonOpponents && $this->hideMode == CombatStartEvent::POSSIBILITY_HIDE) || $this->hideMode == CombatStartEvent::FORCE_SHOW)return false;
    return true;
  }

  private function applyHideNonOpponents(): void{
    if (!$this->canApplyHide() || $this->getPlayer() == null)return;
    $opp = array_flip(array_filter([$this->getName(), $this->getTargetPlayer()?->getName(), $this->getAttackerPlayer()?->getName()]));
    foreach (Server::getInstance()->getOnlinePlayers() as $p) {
      if (isset($opp[$p->getName()]) || isset($this->hiddenPlayers[$p->getName()]))continue;
      $this->getPlayer()?->hidePlayer($p);
      $this->hiddenPlayers[$p->getName()] = true;
    }
  }
  
  public function hidePlayer(Player $player): void{
    if (!$this->canApplyHide())return;
    $opp = array_flip(array_filter([$this->getName(), $this->getTargetPlayer()?->getName(), $this->getAttackerPlayer()?->getName()]));
    if (isset($opp[$player->getName()]) || isset($this->hiddenPlayers[$player->getName()]))return;
    $this->getPlayer()?->hidePlayer($player);
    $this->hiddenPlayers[$player->getName()] = true;
  }

  public function showPlayer(Player $player): void{
    if (!isset($this->hiddenPlayers[$player->getName()]))return;
    $this->getPlayer()?->showPlayer($player);
    unset($this->hiddenPlayers[$player->getName()]);
  }

  private function clearHidden(): void{
    $self = $this->getPlayer();
    if (!$self instanceof Player) {
      $this->hiddenPlayers = [];
      return;
    }
    foreach($this->hiddenPlayers as $name => $_){
      if (($p = Server::getInstance()->getPlayerExact($name)) instanceof Player)$self->showPlayer($p);
    }
    $this->hiddenPlayers = [];
  }

  public function getCombatDuration(): int{
    return $this->combatDuration;
  }

  public function setCombatDuration(int $seconds): void{
    $this->combatDuration = max(0, $seconds);
  }

  public function isLoadedData(): bool{
    return $this->loaded;
  }

  public function loadData(?array $row): void{
    $player = $this->getPlayer();
    if (!$player instanceof Player) {
      Main::getInstance()->getSessionManager()->removeSession($this->name);
      return;
    }
    if ($row !== null) {
      $this->cpsPopup = (bool)$row["cps_popup"];
      $this->cpsDisplay = (bool)$row["cps_display"];
      $this->combatSounds = (bool)$row["combat_sounds"];
      $this->projectilesSound = (bool)$row["projectiles_sound"];
      $this->hideNonOpponents = (bool)$row["hide_non_opponents"];
      $this->sendMessages = (bool)$row["send_messages"];
      $this->healthType = (int)$row["health_type"];
    }
    $this->loaded = true;
  }

  public function save(): void{
    if (($p = $this->getPlayer()) !== null) {
      Main::getInstance()->getProvider()->updatePlayerData($p);
    }
  }

  public function getAllData(): array{
    return [
      "cps_popup" => $this->cpsPopup,
      "cps_display" => $this->cpsDisplay,
      "combat_sounds" => $this->combatSounds,
      "projectiles_sound" => $this->projectilesSound,
      "hide_non_opponents" => $this->hideNonOpponents,
      "send_messages" => $this->sendMessages,
      "health_type" => $this->healthType
    ];
  }

  // Settings 
  public function isCpsPopup(): bool{
    return $this->cpsPopup;
  }
  
  public function setCpsPopup(bool $value): void{
    $this->cpsPopup = $value;
  }
  
  public function isCpsDisplay(): bool{
    return $this->cpsDisplay;
  }
  
  public function setCpsDisplay(bool $value): void{
    $this->cpsDisplay = $value;
  }
  
  public function isCombatSounds(): bool{
    return $this->combatSounds;
  }
  
  public function setCombatSounds(bool $value): void{
    $this->combatSounds = $value;
  }
  
  public function isProjectilesSound(): bool{
    return $this->projectilesSound;
  }
  
  public function setProjectilesSound(bool $value): void{
    $this->projectilesSound = $value;
  }
  
  public function isHideNonOpponents(): bool{
    return $this->hideNonOpponents;
  }
  
  public function setHideNonOpponents(bool $value): void{
    $this->hideNonOpponents = $value;
  }
  
  public function isSendMessages(): bool{
    return $this->sendMessages;
  }
  
  public function setSendMessages(bool $value): void{
    $this->sendMessages = $value;
  }

  public function isHealthDisplay(): bool{
    return $this->healthType !== HealthUtils::TYPE_NONE;
  }

  public function getHealthType(): int{
    return $this->healthType;
  }
  
  public function setHealthType(int $type): void{
    $this->healthType = $type;
  }

  // Utility
  public function getPlayer(): ?Player{
    $player = Server::getInstance()->getPlayerExact($this->name);
    return ($player !== null && $player->isOnline()) ? $player : null;
  }
}
