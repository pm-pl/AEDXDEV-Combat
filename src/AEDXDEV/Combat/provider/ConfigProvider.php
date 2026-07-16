<?php
declare(strict_types=1);

namespace AEDXDEV\Combat\provider;

use AEDXDEV\Combat\Main;

use pocketmine\player\Player;
use pocketmine\utils\Config;

class ConfigProvider extends Provider {

  public function load(): void{
    $settings = $this->plugin->getConfig()->get("DatabaseInfo", []);
    $this->database = new Config(
      $this->plugin->getDataFolder() . ($settings[$settings["type"]]["file"] ?? ("PlayerSettings." . ($settings["type"] == "json" ? "json" : "yml"))),
      $settings["type"] == "json" ? Config::JSON : Config::YAML
    );
  }

  public function loadPlayerData(Player $player): void{
    $data = $this->database->get($player->getXuid(), null);
    $data ??= [
      "xuid" => $player->getXuid(),
      "name" => $player->getName(),
      "cps_popup" => true,
      "cps_display" => true,
      "combat_sounds" => true,
      "projectiles_sound" => true,
      "hide_non_opponents" => false,
      "send_messages" => true,
      "health_type" => 0
    ];
    if ($player->isOnline()) {
      $session = Main::getInstance()->getSessionManager()->get($player);
      if ($session !== null && !$session->isLoadedData()) {
        $session->loadData($data);
      }
    }
  }

  public function updatePlayerData(Player $player): void{
    $session = Main::getInstance()->getSessionManager()->get($player);
    if ($session === null) return;
    $this->database->set($player->getXuid(), array_merge([
      "xuid" => $player->getXuid(),
      "name" => $player->getName(),
    ], $session->getAllData()));
    $this->database->save();
  }

  public function close(): void{
    // NOPE
  }
}