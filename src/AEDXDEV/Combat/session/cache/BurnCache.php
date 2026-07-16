<?php
declare(strict_types=1);

namespace AEDXDEV\Combat\session\cache;

use pocketmine\player\Player;
use pocketmine\world\Position;


final class BurnCache {

  public const TYPE_LAVA = "lava";
  public const TYPE_FIRE = "fire";

  // Time To Live (default 10 sec)
  private float $defaultTtl;

  private array $entries = [];

  public function __construct(float $defaultTtl = 10.0) {
    $this->defaultTtl = max(0.0, $defaultTtl);
  }

  public function tick(): void{
    if (empty($this->entries))return;
    $now = microtime(true);
    foreach ($this->entries as $k => $e) {
      if (($now - $e["at"]) > $this->defaultTtl) {
        unset($this->entries[$k]);
      }
    }
  }

  public function putLava(Player $owner, Position $pos): void{
    $this->record(self::TYPE_LAVA, $owner->getName(), $pos);
  }

  public function putFire(Player $owner, Position $pos): void{
    $this->record(self::TYPE_FIRE, $owner->getName(), $pos);
  }

  public function forgetAt(Position $pos, ?string $type = null): bool{
    if (!isset($this->entries[$key = $this->keyOf($pos)]))return false;
    if ($type !== null && $this->entries[$key]["type"] !== $type)return false;
    unset($this->entries[$key]);
    return true;
  }

  public function forgetNear(Position $pos, float $radius = 1.5, ?string $type = null): int{
    if (empty($this->entries))return 0;
    $now = microtime(true);
    $removed = 0;
    foreach ($this->entries as $k => $e) {
      if (($now - $e["at"]) > $this->defaultTtl) {
        unset($this->entries[$k]);
        continue;
      }
      if ($type !== null && $e["type"] !== $type)continue;
      [$ew, $ex, $ey, $ez] = $this->splitKey($k);
      if ($ew !== $pos->getWorld()->getFolderName())continue;
      $dx = $pos->getFloorX() - $ex;
      $dy = $pos->getFloorY() - $ey;
      $dz = $pos->getFloorZ() - $ez;
      $d2 = ($dx * $dx) + ($dy * $dy) + ($dz * $dz);
      if ($d2 <= ($radius * $radius)) {
        unset($this->entries[$k]);
        $removed++;
      }
    }
    return $removed;
  }

  public function resolveOwnerAt(Position $pos, ?string $type = null, ?float $ttl = null): ?string{
    if (($entry = $this->entries[$this->keyOf($pos)] ?? null) == null)return null;
    if ($type !== null && $entry["type"] !== $type)return null;
    if (!$this->isFresh($entry["at"], $ttl))return null;
    return $entry["owner"];
  }

  public function resolveOwnerNear(Position $pos, ?string $type = null, ?float $ttl = null, float $radius = 4.0): ?string{
    if (empty($this->entries))return null;
    $now = microtime(true);
    $bestOwner = null;
    $bestD2 = null;
    foreach ($this->entries as $k => $e) {
      if (($now - $e["at"]) > ($ttl ?? $this->defaultTtl))continue;
      if ($type !== null && $e["type"] !== $type)continue;
      [$ew, $ex, $ey, $ez] = $this->splitKey($k);
      if ($ew !== $pos->getWorld()->getFolderName())continue;
      $dx = $pos->getFloorX() - $ex;
      $dy = $pos->getFloorY() - $ey;
      $dz = $pos->getFloorZ() - $ez;
      $d2 = ($dx * $dx) + ($dy * $dy) + ($dz * $dz);
      if ($d2 <= ($radius * $radius) && ($bestD2 == null || $d2 < $bestD2)) {
        $bestD2 = $d2;
        $bestOwner = $e["owner"];
      }
    }
    return $bestOwner;
  }

  private function record(string $type, string $ownerName, Position $pos): void{
    $this->entries[$this->keyOf($pos)] = [
      "owner" => $ownerName,
      "at" => microtime(true),
      "type" => $type
    ];
  }

  private function isFresh(float $at, ?float $ttl): bool{
    return (microtime(true) - $at) <= ($ttl ?? $this->defaultTtl);
  }

  private function keyOf(Position $pos): string{
    return implode(":", [
      $pos->getWorld()->getFolderName(),
      $pos->getFloorX(),
      $pos->getFloorY(),
      $pos->getFloorZ()
    ]);
  }

  private function splitKey(string $key): array {
    [$world, $x, $y, $z] = explode(":", $key, 4);
    return [$world, (int)$x, (int)$y, (int)$z];
  }
}