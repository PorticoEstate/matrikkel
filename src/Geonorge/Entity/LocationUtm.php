<?php
/**
 * Represents UTM coordinates (Universal Transverse Mercator)
 */

namespace Iaasen\Matrikkel\Geonorge\Entity;

class LocationUtm
{
    public function __construct(
        public readonly float $north,
        public readonly float $east,
        public readonly string $zone
    ) {}

    public function getNorth(): float
    {
        return $this->north;
    }

    public function getEast(): float
    {
        return $this->east;
    }

    public function getZone(): string
    {
        return $this->zone;
    }

    public function __toString(): string
    {
        return sprintf('UTM Zone %s: East=%.2f, North=%.2f', $this->zone, $this->east, $this->north);
    }
}