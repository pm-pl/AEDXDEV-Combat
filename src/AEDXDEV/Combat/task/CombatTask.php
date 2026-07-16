<?php

namespace AEDXDEV\Combat\task;

use AEDXDEV\Combat\Main;
use pocketmine\scheduler\Task;

class CombatTask extends Task {
	
	public function __construct(
	){
	  // NOPE
	}
	
	public function onRun(): void{
	  Main::getInstance()->getSessionManager()->tickAll();
	  Main::getInstance()->getBurnCache()->tick();
	  Main::getInstance()->getTntCache()->tick();
	}
}
