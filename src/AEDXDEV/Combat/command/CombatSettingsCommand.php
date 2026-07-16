<?php

namespace AEDXDEV\Combat\command;

use pocketmine\player\Player;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\plugin\PluginOwned;

use AEDXDEV\Combat\Main;

class CombatSettingsCommand extends Command implements PluginOwned{

  public function __construct(
    private Main $plugin
  ){
    parent::__construct("combatsettings", "Combat Settings", null, ["csettings"]);
    $this->setPermission("combatsettings.cmd");
  }

  public function execute(CommandSender $sender, string $label, array $args): bool{
    if ($sender instanceof Player) {
      $this->plugin->CombatSettingsForm($sender);
    } else {
      $sender->sendMessage("§cUse this command in-game");
    }
    return true;
  }
  
  public function getOwningPlugin(): Main{
    return $this->plugin;
  }
}