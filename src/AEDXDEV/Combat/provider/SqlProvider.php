<?php

declare(strict_types=1);

namespace AEDXDEV\Combat\provider;

use AEDXDEV\Combat\Main;

use pocketmine\player\Player;

use poggit\libasynql\DataConnector;
use poggit\libasynql\libasynql;
use poggit\libasynql\SqlThread;

use Closure;

final class SqlProvider extends Provider{
  
  //private DataConnector $database;

	public function load(): void{
	  $settings = $this->plugin->getConfig()->get("DatabaseInfo", []);
		$this->database = libasynql::create($this->plugin, $settings, [
			"mysql" => $settings["mysql"]["file"] ?? "PlayerSettings.sql",
			"sqlite" => $settings["sqlite"]["file"] ?? "PlayerSettings.sql"
		]);
		$this->database->executeImplRaw([
  		"CREATE TABLE IF NOT EXISTS combat_players (
    		xuid VARCHAR(16) NOT NULL UNIQUE,
    		name VARCHAR(32) NOT NULL,
    		cps_popup BOOLEAN UNSIGNED DEFAULT TRUE,
    		cps_display BOOLEAN UNSIGNED DEFAULT TRUE,
    		combat_sounds BOOLEAN UNSIGNED DEFAULT TRUE,
    		projectiles_sound BOOLEAN UNSIGNED DEFAULT TRUE,
    		hide_non_opponents BOOLEAN UNSIGNED DEFAULT FALSE,
    		send_messages BOOLEAN UNSIGNED DEFAULT TRUE,
    		health_type INT UNSIGNED DEFAULT 0
  		)"
  	], [[]], [SqlThread::MODE_GENERIC], function(){}, null);
	}
	
	public function loadPlayerData(Player $player): void{
    $this->database->executeImplRaw(["SELECT * FROM combat_players WHERE xuid = :xuid"], [[":xuid" => $player->getXuid()]], [SqlThread::MODE_SELECT], function(array $rows) use ($player): void{
      if (empty($results = $rows[0]->getRows())) {
        $this->database->executeImplRaw(["INSERT INTO combat_players (xuid, name) VALUES (:xuid, :name)"], [[":xuid" => $player->getXuid(), ":name" => $player->getName()]], [SqlThread::MODE_CHANGE], function() use ($player): void{
          $this->loadPlayerData($player);
        }, null);
        return;
      }
      if ($player->isOnline()) {
        $session = Main::getInstance()->getSessionManager()->get($player);
        if ($session !== null && !$session->isLoadedData()) {
          $session->loadData($results[0]);
        }
      }
    }, null);
  }
	
	public function updatePlayerData(Player $player): void{
    if (($session = Main::getInstance()->getSessionManager()->get($player)) == null)return;
    $set = [];
    $params = [":xuid" => $player->getXuid(), ":name" => $player->getName()];
    foreach ($session->getAllData() as $col => $val) {
      $set[] = "$col = :$col";
      $params[":$col"] = $val;
    }
    $this->database->executeImplRaw(["UPDATE combat_players SET name = :name, " . implode(", ", $set) . " WHERE xuid = :xuid"], [$params], [SqlThread::MODE_CHANGE], function(){}, null);
	}

  public function close(): void{
    $this->database->close();
  }
}