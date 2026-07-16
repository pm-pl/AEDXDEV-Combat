<?php
declare(strict_types=1);

namespace AEDXDEV\Combat\listener;

use AEDXDEV\Combat\Main;
use AEDXDEV\Combat\event\CombatEvent;
use AEDXDEV\Combat\event\CombatStartEvent;
use AEDXDEV\Combat\event\CombatAttackEvent;
use AEDXDEV\Combat\session\PlayerSession;
use AEDXDEV\Combat\session\cache\TntCache;

use pocketmine\event\Listener;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\entity\EntityCombustByBlockEvent;
use pocketmine\event\entity\EntityCombustByEntityEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityExplodeEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\player\PlayerBucketEmptyEvent;
use pocketmine\event\player\PlayerBucketFillEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\server\CommandEvent;
use pocketmine\event\server\DataPacketReceiveEvent;

use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemOnEntityTransactionData;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;

use pocketmine\block\Fire;
use pocketmine\block\Lava;
use pocketmine\block\TNT;
use pocketmine\block\VanillaBlocks;
use pocketmine\item\FlintSteel;
use pocketmine\player\Player;
use pocketmine\scheduler\ClosureTask;
use pocketmine\world\Position;
use pocketmine\entity\projectile\Projectile;
use pocketmine\entity\object\PrimedTNT;
use pocketmine\entity\EntityDataHelper;
use pocketmine\math\Vector3;
use pocketmine\world\sound\PopSound;

use pocketmine\Server;

final class EventListener implements Listener {

  public function __construct(
    private Main $plugin
  ) {
    // NOPE
  }

  /* 
   *  PLAYER JOIN / QUIT
   */

  /**
   * @priority NORMAL
   */
  public function onJoin(PlayerJoinEvent $event): void{
    $player = $event->getPlayer();
    $this->plugin->getSessionManager()->createSession($player);
    foreach (Server::getInstance()->getOnlinePlayers() as $onlinePlayer) {
      $session = $this->plugin->getSessionManager()->get($onlinePlayer);
      if ($session !== null && $session->isInCombat()) {
        if ($session->getAttackerPlayer()?->getName() !== $player->getName() && $session->getTargetPlayer()?->getName() !== $player->getName())$session->hidePlayer($player);
      }
    }
  }

  /**
   * @priority NORMAL
   */
  public function onQuit(PlayerQuitEvent $event): void{
    $player = $event->getPlayer();
    foreach (Server::getInstance()->getOnlinePlayers() as $onlinePlayer) {
      $this->plugin->getSessionManager()->get($onlinePlayer)?->showPlayer($player);
    }
    $this->plugin->getSessionManager()->removeSession($player);
  }

  /**
   * @priority HIGH
   */
  public function onPlayerDeath(PlayerDeathEvent $event): void{
    $session = $this->plugin->getSessionManager()->get($event->getPlayer());
    $session?->onDie();
  }

  /**
   * DAMAGE PRE-PROCESS (Silent Updates Only)
   * @priority LOWEST
   */
    /**
   * DAMAGE PRE-PROCESS (Silent Updates Only)
   * @priority LOWEST
   */
  public function onDamagePre(EntityDamageEvent $event): void{
    if (!Main::$isPluginEnabled)return;
    if (!($victim = $event->getEntity()) instanceof Player)return;
    if (!$this->plugin->getCombatArenaManager()->canStartCombat($victim))return;
    $sm = $this->plugin->getSessionManager();
    if ($event instanceof EntityDamageByEntityEvent) {
      $attacker = null;
      $causeEv = CombatEvent::CAUSE_PLAYER_ATTACK;
      if (($damager = $event->getDamager()) instanceof Player) {
        $attacker = $damager;
      } elseif ($damager instanceof Projectile) {
        if (($owner = $damager->getOwningEntity()) instanceof Player) {
          $attacker = $owner;
          $causeEv = CombatEvent::CAUSE_PROJECTILE;
        }
      }
      if ($attacker instanceof Player) {
        $aSession = $sm->get($attacker);
        $vSession = $sm->get($victim);
        if ($aSession == null || $vSession == null)return;
        $aSession->attackPlayerSilent($vSession, $causeEv);
        $vSession->gotAttackSilent($aSession, $causeEv);
      }
    }
    // Handle death silently for other plugins
    if ($victim->getHealth() <= $event->getFinalDamage())$sm->get($victim)?->onDieSilent();
  }

  /**
   * COMBAT DAMAGE (Real Logic)
   * @priority HIGH
   */
  public function onDamageCombat(EntityDamageEvent $event): void{
    if (!Main::$isPluginEnabled || $event->isCancelled())return;
    if (!($victim = $event->getEntity()) instanceof Player)return;
    if (!$this->plugin->getCombatArenaManager()->canStartCombat($victim))return;
    $sm = $this->plugin->getSessionManager();
    if ($event instanceof EntityDamageByEntityEvent) {
      $attacker = null;
      $entityId = null;
      $causeEv = CombatEvent::CAUSE_PLAYER_ATTACK;
      if (($damager = $event->getDamager()) instanceof Player) {
        $attacker = $damager;
        $entityId = $damager->getId();
      } elseif ($damager instanceof Projectile) {
        if (($owner = $damager->getOwningEntity()) instanceof Player) {
          $attacker = $owner;
          $causeEv = CombatEvent::CAUSE_PROJECTILE;
          $entityId = $damager->getId();
        } else return;
      } else return;
      $aSession = $sm->get($attacker);
      $vSession = $sm->get($victim);
      if ($aSession == null || $vSession == null)return;
      $samePair = $aSession->getTargetPlayer()?->getName() == $victim->getName() || $aSession->getAttackerPlayer()?->getName() == $victim->getName();
      if ($this->plugin->getConfig()->get("BlockNonOpponents", false)) {
        if (!($samePair || (!$aSession->isInCombat() && !$vSession->isInCombat()))) {
          $event->cancel();
          if ($aSession->isSendMessages()) {
            $attacker->sendMessage($this->plugin->getMessage("NonOpponents"));
          }
          return;
        }
      }
      if ((!$aSession->isInCombat() && !$vSession->isInCombat()) || !$samePair) {
        $startEv = new CombatStartEvent($aSession, $vSession, $this->plugin->getConfig()->get("Timer", 10), $causeEv, $entityId);
        $startEv->call();
        if ($startEv->isCancelled()) {
          $event->cancel();
          return;
        }
        $aSession->setHideMode($startEv->getHideMode());
        $vSession->setHideMode($startEv->getHideMode());
        $aSession->setCombatDuration($startEv->getTimer());
        $vSession->setCombatDuration($startEv->getTimer());
      }
      $attackEv = new CombatAttackEvent($aSession, $vSession, $causeEv, $entityId);
      $attackEv->call();
      if ($attackEv->isCancelled()) {
        $event->cancel();
        if ($attackEv->isNonOpponents() && $aSession->isSendMessages()) {
          $attacker->sendMessage("§cYou can only hit your current opponent.");
        }
        return;
      }
      $aSession->attackPlayer($vSession, $causeEv);
    } elseif (in_array(($cause = $event->getCause()), [EntityDamageEvent::CAUSE_FALL, EntityDamageEvent::CAUSE_VOID])) {
      $vSession = $sm->get($victim);
      if ($vSession->isInCombat() && ($attacker = $vSession->getAttackerPlayer()) !== null) {
        $attacker->attackPlayer($vSession, $cause == EntityDamageEvent::CAUSE_FALL ? CombatEvent::CAUSE_FALL : CombatEvent::CAUSE_VOID);
      }
    } elseif (in_array($cause, [EntityDamageEvent::CAUSE_LAVA, EntityDamageEvent::CAUSE_FIRE, EntityDamageEvent::CAUSE_FIRE_TICK])) {
      $cache = $this->plugin->getBurnCache();
      $pos = $victim->getPosition();
      $ttl = (float) $this->plugin->getConfig()->get("KillCreditTtl", 10.0);
      $owner = $cause === EntityDamageEvent::CAUSE_LAVA ? $cache->resolveOwnerNear($pos, "lava", $ttl, 4.0) : ($cache->resolveOwnerNear($pos, "fire", $ttl, 1.0) ?? $cache->resolveOwnerNear($pos, "lava", $ttl, 4.0));
      if ($owner !== null) {
        $sm->get($victim)->gotAttackSilent($sm->get($owner), $cause === EntityDamageEvent::CAUSE_LAVA ? CombatEvent::CAUSE_LAVA : CombatEvent::CAUSE_FIRE);
      }
    }
  }


  /* OLD *//*
  public function onDamage(EntityDamageEvent $event): void{
    if (!Main::$isPluginEnabled)return;
    if (!($victim = $event->getEntity()) instanceof Player)return;
    if (!$this->plugin->getCombatArenaManager()->canStartCombat($victim))return;
    $sm = $this->plugin->getSessionManager();
    if ($victim->getHealth() <= $event->getFinalDamage()) {
      $sm->get($victim)?->onDie();
    }
    if ($event->isCancelled())return;
    if ($event instanceof EntityDamageByEntityEvent) {
      $attackerPlayer = null;
      $entityId = null;
      $causeEv = CombatEvent::CAUSE_PLAYER_ATTACK;
      if (($damager = $event->getDamager()) instanceof Player) {
        $attackerPlayer = $damager;
        $entityId = $damager->getId();
      } elseif($damager instanceof Projectile) {
        if (($owner = $damager->getOwningEntity()) instanceof Player) {
          $attackerPlayer = $owner;
          $causeEv = CombatEvent::CAUSE_PROJECTILE;
          $entityId = $damager->getId();
          if ($sm->get($owner)->isProjectilesSound()) {
            Main::getInstance()->playSound($owner, "Projectile");
            //$owner->broadcastSound([$owner], new PopSound());
          }
        } else return;
      } else {
        if (($owner = $damager->getOwningEntity()) instanceof Player) {
          $attackerPlayer = $owner;
          $entityId = $damager->getId();
          $causeEv = CombatEvent::CAUSE_ENTITY_ATTACK;
        } else return;
      }
      $aSession = $sm->get($attackerPlayer);
      $vSession = $sm->get($victim);
      $samePair = $aSession->getTargetPlayer()?->getName() == $victim->getName() || $aSession->getAttackerPlayer()?->getName() == $victim->getName();
      $allowHit = true;
      if ($this->plugin->getConfig()->get("BlockNonOpponents", false)) {
        $allowHit = $samePair || (!$aSession->isInCombat() && !$vSession->isInCombat());
      }
      if (!$allowHit){
        $event->cancel();
        if ($aSession->isSendMessages()) {
          $attackerPlayer->sendMessage($this->plugin->getMessage("NonOpponents"));
        }
        return;
      }
      if ((!$aSession->isInCombat() && !$vSession->isInCombat()) || !$samePair) {
        $startEv = new CombatStartEvent($aSession, $vSession, $this->plugin->getConfig()->get("Timer", 10), $causeEv, $entityId);
        $startEv->call();
        if ($startEv->isCancelled()) {
          $event->cancel();
          return;
        }
        $aSession->setHideMode($startEv->getHideMode());
        $aSession->setCombatDuration($startEv->getTimer());
        $vSession->setHideMode($startEv->getHideMode());
        $vSession->setCombatDuration($startEv->getTimer());
      }
      $attackEv = new CombatAttackEvent($aSession, $vSession, $causeEv, $entityId);
      $attackEv->call();
      if ($attackEv->isCancelled()) {
        $event->cancel();
        if ($aSession->isSendMessages()) {
          $attackerPlayer->sendMessage("§cYou can only hit your current opponent.");
        }
        return;
      }
      $aSession->attackPlayer($vSession, $causeEv);
    } elseif (in_array(($cause = $event->getCause()), [EntityDamageEvent::CAUSE_FALL, EntityDamageEvent::CAUSE_VOID])) {
      $vSession = $sm->get($victim);
      if ($vSession->isInCombat() && ($attacker = $vSession->getAttackerPlayer()) !== null) {
        $attacker->attackPlayer($vSession, $cause == EntityDamageEvent::CAUSE_FALL ? CombatEvent::CAUSE_FALL : CombatEvent::CAUSE_VOID);
      }
    } elseif (in_array($cause, [EntityDamageEvent::CAUSE_LAVA, EntityDamageEvent::CAUSE_FIRE, EntityDamageEvent::CAUSE_FIRE_TICK])) {
      $cache = $this->plugin->getBurnCache();
      $pos = $victim->getPosition();
      $ttl = (float) $this->plugin->getConfig()->get("KillCreditTtl", 10.0);
      $owner = $cause === EntityDamageEvent::CAUSE_LAVA ? $cache->resolveOwnerNear($pos, "lava", $ttl, 4.0) : ($cache->resolveOwnerNear($pos, "fire", $ttl, 1.0) ?? $cache->resolveOwnerNear($pos, "lava", $ttl, 4.0));
      if ($owner !== null) {
        $sm = $this->plugin->getSessionManager();
        $attackerS = $sm->get($owner);
        $victimS = $sm->get($victim);
        if ($attackerS && $victimS) {
          $victimS->gotAttackSilent($attackerS, $cause === EntityDamageEvent::CAUSE_LAVA ? CombatEvent::CAUSE_LAVA : CombatEvent::CAUSE_FIRE);
        }
      }
    }
  }*/

  /* ---------------------------------------------------------------------- *
   *  LAVA / FIRE OWNERSHIP
   * ---------------------------------------------------------------------- */

  /**
   * @priority HIGH
   */
  public function onCombustByEntity(EntityCombustByEntityEvent $event): void{
    if (!Main::$isPluginEnabled || $event->isCancelled())return;
    if (!($victim = $event->getEntity()) instanceof Player)return;
    if (!$this->plugin->getCombatArenaManager()->canStartCombat($victim))return;
    $combuster = $event->getCombuster();
    $attacker = null;
    $entityId = null;
    if ($combuster instanceof Player) {
      $attacker = $combuster;
      $entityId = $combuster->getId();
    } elseif ($combuster instanceof Projectile && ($owner = $combuster->getOwningEntity()) instanceof Player) {
      if (($owner = $combuster->getOwningEntity()) instanceof Player) {
        $attacker = $owner;
        $entityId = $combuster->getId();
      }
    }
    if ($attacker instanceof Player) {
      $sm = $this->plugin->getSessionManager();
      $aSession = $sm->get($attacker);
      $vSession = $sm->get($victim);
      $samePair = $aSession->getTargetPlayer()?->getName() == $victim->getName() || $aSession->getAttackerPlayer()?->getName() == $victim->getName();
      if ($this->plugin->getConfig()->get("BlockNonOpponents", false)) {
        if (!($samePair || (!$aSession->isInCombat() && !$vSession->isInCombat()))) {
          $event->cancel();
          if ($aSession->isSendMessages()) {
            $attacker->sendMessage($this->plugin->getMessage("NonOpponents"));
          }
          return;
        }
      }
      if ((!$aSession->isInCombat() && !$vSession->isInCombat()) || !$samePair) {
        $startEv = new CombatStartEvent($aSession, $vSession, $this->plugin->getConfig()->get("Timer", 10), CombatEvent::CAUSE_FIRE, $entityId);
        $startEv->call();
        if ($startEv->isCancelled()) {
          $event->cancel();
          return;
        }
        $aSession->setCombatDuration($startEv->getTimer());
        $vSession->setCombatDuration($startEv->getTimer());
      }
      $attackEv = new CombatAttackEvent($aSession, $vSession, CombatEvent::CAUSE_FIRE, $entityId);
      $attackEv->call();
      if ($attackEv->isCancelled()) {
        $event->cancel();
        if ($this->plugin->getConfig()->get("BlockNonOpponents", false) && $aSession->isSendMessages()) {
          $attacker->sendMessage("§cYou can only hit your current opponent.");
        }
        return;
      }
      $aSession->attackPlayer($vSession, CombatEvent::CAUSE_FIRE);
    }
  }

  /**
   * @priority LOW
   */
  public function onCombustByBlock(EntityCombustByBlockEvent $event): void{
    if (!Main::$isPluginEnabled || $event->isCancelled())return;
    if (!($victim = $event->getEntity()) instanceof Player)return;
    if (!$this->plugin->getCombatArenaManager()->canStartCombat($victim))return;
    $block = $event->getCombuster();
    $ownerName = $this->plugin->getBurnCache()->resolveOwnerNear(
      $block->getPosition(),
      ($block instanceof Lava) ? "lava" : "fire",
      $this->plugin->getConfig()->get("KillCreditTtl", 10.0),
      ($block instanceof Lava) ? 4.0 : 1.0
    );
    if($ownerName === null) return;
    $sm = $this->plugin->getSessionManager();
    $sm->get($victim)->gotAttackSilent($sm->get($ownerName), $block instanceof Lava ? CombatEvent::CAUSE_LAVA : CombatEvent::CAUSE_FIRE);
  }

  /* ---------------------------------------------------------------------- *
   *  ENVIRONMENT INTERACTIONS
   * ---------------------------------------------------------------------- */

  /**
   * @priority LOW
   */
  public function onBucketEmpty(PlayerBucketEmptyEvent $event): void{
    if (!Main::$isPluginEnabled || $event->isCancelled())return;
    $player = $event->getPlayer();
    if (!$this->plugin->getCombatArenaManager()->canStartCombat($player))return;
    $pos = $event->getBlockClicked()->getPosition()->getSide($event->getBlockFace());
    $this->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player, $pos): void{
      if ($pos->getWorld()->getBlock($pos) instanceof Lava) {
        $this->plugin->getBurnCache()->putLava($player, $pos);
      }
    }), 1);
  }

  /**
   * @priority LOW
   */
  public function onBucketFill(PlayerBucketFillEvent $event): void{
    if (!Main::$isPluginEnabled || $event->isCancelled())return;
    if (!$this->plugin->getCombatArenaManager()->canStartCombat($event->getPlayer()))return;
    $pos = $event->getBlockClicked()->getPosition();
    if ($pos->getWorld()->getBlock($pos) instanceof Lava) {
      $this->plugin->getBurnCache()->forgetAt($pos, "lava");
      $this->plugin->getBurnCache()->forgetNear($pos, 1.5, "lava");
    }
  }

  /**
   * @priority LOW
   */
  public function onInteract(PlayerInteractEvent $event): void{
    if (!Main::$isPluginEnabled || $event->isCancelled())return;
    $player = $event->getPlayer();
    if (!$this->plugin->getCombatArenaManager()->canStartCombat($player))return;
    if ($event->getItem() instanceof FlintSteel) {
      $this->plugin->getBurnCache()->putFire($player, $event->getBlock()->getPosition()->getSide($event->getFace()));
    }
  }

  /**
   * @priority LOW
   */
  public function onBlockPlace(BlockPlaceEvent $event): void{
    if (!Main::$isPluginEnabled || $event->isCancelled())return;
    $player = $event->getPlayer();
    if (!$this->plugin->getCombatArenaManager()->canStartCombat($player))return;
    $against = $event->getBlockAgainst();
    if ($against instanceof Fire) {
      $this->plugin->getBurnCache()->forgetAt($against->getPosition(), "fire");
    } elseif ($against instanceof Lava) {
      $this->plugin->getBurnCache()->forgetAt($against->getPosition(), "lava");
    }
    // TNT placement — register ownership and optionally auto-ignite
    foreach ($event->getTransaction()->getBlocks() as [$x, $y, $z, $block]) {
      if (!$block instanceof TNT)continue;
      $tntPos = new Position($x, $y, $z, $player->getWorld());
      $this->plugin->getTntCache()->putTnt($player, $tntPos);
      if ($this->plugin->getConfig()->get("AutoActivateTnt", true)) {
        $this->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($tntPos, $player): void{
            $world = $tntPos->getWorld();
            if ($world->getBlock($tntPos) instanceof TNT) {
              $world->setBlock($tntPos, VanillaBlocks::AIR());
              $tnt = new PrimedTNT(EntityDataHelper::parseLocation(new Vector3($tntPos->x + 0.5, $tntPos->y, $tntPos->z + 0.5), $world), null);
              $tnt->spawnToAll();
            }
          }
        ), 1);
      }
    }
  }

  /**
   * TNT explosion — assign kill credit to the player who placed it.
   * Fired for any entity explosion (PrimedTNT, creepers, etc.).
   * We only care when the source position maps to a known TntCache entry.
   *
   * @priority NORMAL
   */
  public function onEntityExplode(EntityExplodeEvent $event): void{
    if (!Main::$isPluginEnabled || $event->isCancelled())return;
    $entity = $event->getEntity();
    if (!$entity instanceof PrimedTNT)return;
    $pos = $entity->getPosition();
    $ttl = (float) $this->plugin->getConfig()->get("KillCreditTtl", 10.0);
    $owner = $this->plugin->getTntCache()->resolveOwnerNear($pos, $ttl);
    // Clean up cache entries near the explosion so they don't linger
    $this->plugin->getTntCache()->forgetNear($pos);
    if ($owner === null)return;
    $sm = $this->plugin->getSessionManager();
    $aSession = $sm->get($owner);
    if ($aSession === null)return;
    // Walk online players and tag those within explosion radius
    foreach ($entity->getWorld()->getPlayers() as $p) {
      if ($p->getName() === $owner)continue;
      $dist = $p->getPosition()->distance($pos);
      if ($dist > TntCache::EXPLOSION_RADIUS)continue;
      $vSession = $sm->get($p);
      if ($vSession === null)continue;
      if (!$this->plugin->getCombatArenaManager()->canStartCombat($p)) continue;
      // Silent update so kill credit is ready; actual damage event fires separately
      $aSession->attackPlayerSilent($vSession, CombatEvent::CAUSE_BLOCK_EXPLOSION);
      $vSession->gotAttackSilent($aSession, CombatEvent::CAUSE_BLOCK_EXPLOSION);
    }
  }

  /**
   * @priority LOW
   */
  public function onBlockBreak(BlockBreakEvent $event): void{
    if (!Main::$isPluginEnabled || $event->isCancelled())return;
    if (!$this->plugin->getCombatArenaManager()->canStartCombat($event->getPlayer()))return;
    $block = $event->getBlock();
    if ($block instanceof Fire) {
      $this->plugin->getBurnCache()->forgetAt($block->getPosition(), "fire");
    } elseif($block instanceof Lava) {
      $this->plugin->getBurnCache()->forgetNear($block->getPosition(), 2.0, "lava");
    } elseif($block instanceof TNT) {
      $this->plugin->getTntCache()->forgetNear($block->getPosition());
    }
  }

  /* ---------------------------------------------------------------------- *
   *  COMMAND + PACKET CPS
   * ---------------------------------------------------------------------- */

  /**
   * @priority NORMAL
   */
  public function onUseCommand(CommandEvent $event): void{
    if (!($player = $event->getSender()) instanceof Player)return;
    $session = $this->plugin->getSessionManager()->get($player);
    $command = "/" . strtolower(explode(" ", $event->getCommand())[0]);
    if ($session->isInCombat() && in_array($command, $this->plugin->getConfig()->get("BlockedCommands", [])))$event->cancel();
  }

  /**
   * @priority NORMAL
   */
  public function onDataPacketReceive(DataPacketReceiveEvent $event) : void{
		if (!Main::$isPluginEnabled || $event->isCancelled())return;
		if (($player = $event->getOrigin()->getPlayer()) == null)return;
		if (($session = $this->plugin->getSessionManager()->get($player)) == null)return;
		if (!$this->plugin->getCombatArenaManager()->canStartCombat($player))return;
		$packet = $event->getPacket();
		switch ($packet->pid()) {
			case LevelSoundEventPacket::NETWORK_ID:
				if ($packet->sound === LevelSoundEvent::ATTACK_NODAMAGE)$session->addClick();
			break;
			case InventoryTransactionPacket::NETWORK_ID:
				if ($packet->trData->getTypeId() == InventoryTransactionPacket::TYPE_USE_ITEM_ON_ENTITY && $packet->trData->getActionType() == UseItemOnEntityTransactionData::ACTION_ATTACK)$session->addClick();
				break;
		}
	}
}