# AdvancedCombat
This plugin prevents players from escaping during combat, manages combat interactions, tracks CPS, and shows health info.

---
## Features
- **Combat Management**: Automatically tags players in combat when they attack each other and removes them after a timer.  
- **Kill Credit**: Correctly assigns kills across melee, projectiles, fire/lava, TNT explosions, and fall/void damage taken while in combat.
- **TNT Kill Credit**: Tracks who placed a TNT block so the kill is credited to them, not to "environment" — includes auto-ignite option.
- **Same Combat Enforcement**: Optionally cancel interactions if players aren't engaged with the same opponent.  
- **CPS Tracking**: Counts player clicks per second, with action bar popup and optional score tag display.  
- **Health Display**: Show player health above their head (number or bar).  
- **Hide Non-Opponents**: Focused 1v1 mode that hides other players.  
- **Messages & Sounds**: Sends customizable messages and plays sounds during combat events.  
- **Database Support**: Store player settings in `yml/json` or MySQL/SQLite (via libasynql).  
- **Combat Events**: API events for developers (start, attack, end).  

---
## API

### Get Combat Status
```php
use AEDXDEV\Combat\Main as Combat;

// Returns true if the player is in combat
Combat::getInstance()->getSessionManager()->get($player)?->isInCombat();
```

### Get Last Attacker / Last Target
```php
$session = Combat::getInstance()->getSessionManager()->get($player);

$attacker = $session->getAttackerPlayer();
$target = $session->getTargetPlayer();
```

### Example Event Usage
```php
use AEDXDEV\Combat\event\CombatEvent;
use AEDXDEV\Combat\event\CombatStartEvent;
use AEDXDEV\Combat\event\CombatAttackEvent;
use AEDXDEV\Combat\event\CombatEndEvent;

public function onCombatStart(CombatStartEvent $event): void{
  $attacker = $event->getAttacker();
  $victim = $event->getVictim();
  $event->setTimer(15); // change duration
  if ($event->isNonOpponents()) {
    $event->cancel(); // cancel the event = cancel damage
  }
  // Hide-non-opponents behavior for this combat:
  // CombatStartEvent::POSSIBILITY_HIDE (default: use each player's own settings)
  // CombatStartEvent::FORCE_HIDE (always hide, ignores player settings)
  // CombatStartEvent::FORCE_SHOW (never hide, ignores player settings)
  $event->setHideMode(CombatStartEvent::FORCE_SHOW);
}

public function onCombatAttack(CombatAttackEvent $event): void{
  if ($event->isNonOpponents()) {
    $event->cancel(); // block cross-combat hits
  }
  // $event->getCause() returns one of CombatEvent::CAUSE_* (see below)
}

public function onCombatEnd(CombatEndEvent $event): void{
  if ($event->getCause() === CombatEndEvent::CAUSE_DIE) {
    $victim = $event->getPlayer();
    $killer = $event->getAttacker(); // null if no one gets credit
    $lastTarget = $event->getTarget();
  }
}
```

### Damage Causes
`CombatEvent::CAUSE_*` constants tell you how a hit or kill happened:

| Constant | Meaning |
|---|---|
| `CAUSE_PLAYER_ATTACK` | Direct melee hit |
| `CAUSE_ENTITY_ATTACK` | Hit by a non-projectile entity owned by a player |
| `CAUSE_PROJECTILE` | Arrow, snowball, etc. |
| `CAUSE_FALL` | Fall damage while linked to an attacker |
| `CAUSE_FIRE` | Set on fire (flint & steel or fire block) |
| `CAUSE_LAVA` | Burned by lava someone placed |
| `CAUSE_VOID` | Knocked/fell into the void while linked to an attacker |
| `CAUSE_BLOCK_EXPLOSION` | Killed by a TNT explosion someone placed |

### Combat End Causes
`CombatEndEvent::CAUSE_*` tells you why combat ended:
- `CAUSE_TIME` — the timer ran out
- `CAUSE_DIE` — the player died (killer available via `getAttacker()`, if any)
- `CAUSE_KILL` — the player got a kill (target available via `getTarget()`)

---
## Configuration
Configure the plugin through `config.yml`:

```yaml

Enable: true # Enables or disables the plugin completely.
UseAllowedWorldOnly: false # If true, combat only works in AllowedWorld list.
BlockNonOpponents: false # If true, players can only hit their current opponent.
AutoActivateTnt: true # If true, TNT placed will auto ignite (useful for PvP modes).
SendMessages: true # If true, sends combat messages (Start/End/Kill/Death).
DeathMessages: true # If true, send kill & death messages to players.
Messages: # Customizable messages.
  Start: "§eYou are now in combat with {PLAYER}"
  NonOpponents: "§cYou can only hit your current opponent."
  End: "§eYou are no longer in combat."
  Kill: "§aYou killed §f{PLAYER}§a!"
  Death: "§cYou were killed by §c{PLAYER}§c!"
  BlockedCommand: "§cYou cannot use this command while in combat!"
  SaveSettings: "§aYour settings have been saved!"
Sounds: # Customizable sounds for each combat event.
  # Note: If "name" is empty, the sound will not play.
  Start:
    name: "random.orb"
    volume: 1.0
    pitch: 1.0
  Kill:
    name: "note.pling"
    volume: 1.0
    pitch: 1.5
  Death:
    name: "note.bass"
    volume: 1.0
    pitch: 1.0
  End:
    name: "note.bass"
    volume: 1.0
    pitch: 0.7
  Projectile:
    name: "random.orb"
    volume: 1.0
    pitch: 1.0
  SaveSettings:
    name: "random.levelup"
    volume: 1.0
    pitch: 1.0
AllowedWorld: # List of worlds where combat is allowed (if UseAllowedWorldOnly = true).
  - arena
BlockedCommands: # Commands that are blocked while in combat.
  - /hub
  - /spawn
KillCreditWindow: 10.0 # Time window (seconds) to give credit to attacker for a kill (melee/projectile link).
KillCreditTtl: 10.0 # Time-to-live for placement caches (fire/lava/TNT ownership credit).
Timer: 10 # Duration of combat (in seconds).
DatabaseInfo: # Database configuration for saving player settings.
  type: mysql # Options: mysql, sqlite, json, yaml
  mysql:
    file: "PlayerSettings.sql"
    host: "127.0.0.1"
    username: "root"
    password: ""
    schema: "your_schema"
    port: 3306
  sqlite:
    file: "PlayerSettings.sql"
  json:
    file: "PlayerSettings.json"
  yaml:
    file: "PlayerSettings.yml"
  worker-limit: 1 # Async worker limit for libasynql
```

---
## 💬 Support

| Platform | Contact |
|---|---|
| Discord | `aedxdev` |
| YouTube | [AEDX DEV](https://youtube.com/@aedxdev?si=RG-8HrkGhFy4kbHI) |
| GitHub | [aedxdev](https://github.com/aedxdev) |
| Email | aedxdev@gmail.com |
| Donate | [paypal.me/AEDXDEV](https://paypal.me/AEDXDEV) |

---
