<?php
declare(strict_types=1);

namespace AEDXDEV\Combat\session\cache;

use pocketmine\player\Player;
use pocketmine\world\Position;

/**
 * Tracks TNT placements by players so kill credit can be assigned
 * when a victim dies from a TNT explosion.
 *
 * Architecture mirrors BurnCache — each entry is keyed by the
 * world-local block position of the TNT and expires after a TTL.
 */
final class TntCache {

  // TNT explosion radius in PM5 is ~4 blocks; we use a slightly
  // larger search radius (7.0) to account for edge placements.
  public const EXPLOSION_RADIUS = 7.0;

  private float $defaultTtl;

  /** @var array<string, array{owner: string, at: float}> */
  private array $entries = [];

  public function __construct(float $defaultTtl = 10.0) {
    $this->defaultTtl = max(0.0, $defaultTtl);
  }

  // -----------------------------------------------------------------------
  //  Write
  // -----------------------------------------------------------------------

  /**
   * Register a TNT block placed (or primed) by $owner at $pos.
   */
  public function putTnt(Player $owner, Position $pos): void {
    $this->entries[$this->keyOf($pos)] = [
      "owner" => $owner->getName(),
      "at"    => microtime(true),
    ];
  }

  /**
   * Remove a specific position (e.g. player picked the TNT back up,
   * or the block was broken before it exploded).
   */
  public function forgetAt(Position $pos): bool {
    $key = $this->keyOf($pos);
    if (!isset($this->entries[$key])) return false;
    unset($this->entries[$key]);
    return true;
  }

  /**
   * Remove all entries within $radius blocks of $pos.
   * Call this after an explosion so stale entries don't linger.
   */
  public function forgetNear(Position $pos, float $radius = self::EXPLOSION_RADIUS): int {
    if (empty($this->entries)) return 0;
    $now     = microtime(true);
    $removed = 0;
    foreach ($this->entries as $k => $e) {
      if (($now - $e["at"]) > $this->defaultTtl) {
        unset($this->entries[$k]);
        continue;
      }
      [$ew, $ex, $ey, $ez] = $this->splitKey($k);
      if ($ew !== $pos->getWorld()->getFolderName()) continue;
      $dx = $pos->getFloorX() - $ex;
      $dy = $pos->getFloorY() - $ey;
      $dz = $pos->getFloorZ() - $ez;
      if (($dx * $dx + $dy * $dy + $dz * $dz) <= ($radius * $radius)) {
        unset($this->entries[$k]);
        $removed++;
      }
    }
    return $removed;
  }

  // -----------------------------------------------------------------------
  //  Read
  // -----------------------------------------------------------------------

  /**
   * Find the owner of the nearest TNT entry within $radius blocks of $pos.
   * Returns null if nothing is found or everything has expired.
   */
  public function resolveOwnerNear(
    Position $pos,
    ?float $ttl    = null,
    float  $radius = self::EXPLOSION_RADIUS
  ): ?string {
    if (empty($this->entries)) return null;
    $now       = microtime(true);
    $useTtl    = $ttl ?? $this->defaultTtl;
    $bestOwner = null;
    $bestD2    = null;
    foreach ($this->entries as $k => $e) {
      if (($now - $e["at"]) > $useTtl) continue;
      [$ew, $ex, $ey, $ez] = $this->splitKey($k);
      if ($ew !== $pos->getWorld()->getFolderName()) continue;
      $dx = $pos->getFloorX() - $ex;
      $dy = $pos->getFloorY() - $ey;
      $dz = $pos->getFloorZ() - $ez;
      $d2 = $dx * $dx + $dy * $dy + $dz * $dz;
      if ($d2 <= ($radius * $radius) && ($bestD2 === null || $d2 < $bestD2)) {
        $bestD2    = $d2;
        $bestOwner = $e["owner"];
      }
    }
    return $bestOwner;
  }

  // -----------------------------------------------------------------------
  //  Housekeeping
  // -----------------------------------------------------------------------

  /**
   * Purge expired entries. Called every tick from CombatTask.
   */
  public function tick(): void {
    if (empty($this->entries)) return;
    $now = microtime(true);
    foreach ($this->entries as $k => $e) {
      if (($now - $e["at"]) > $this->defaultTtl) {
        unset($this->entries[$k]);
      }
    }
  }

  // -----------------------------------------------------------------------
  //  Helpers
  // -----------------------------------------------------------------------

  private function keyOf(Position $pos): string {
    return implode(":", [
      $pos->getWorld()->getFolderName(),
      $pos->getFloorX(),
      $pos->getFloorY(),
      $pos->getFloorZ(),
    ]);
  }

  /** @return array{string, int, int, int} */
  private function splitKey(string $key): array {
    [$world, $x, $y, $z] = explode(":", $key, 4);
    return [$world, (int) $x, (int) $y, (int) $z];
  }
}
