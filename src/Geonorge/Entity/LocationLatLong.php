<?php
/**
 * Represents Latitude/Longitude coordinates (WGS84)
 */

namespace Iaasen\Geonorge\Entity;

class LocationLatLong
{
    public function __construct(
        public readonly float $latitude,
        public readonly float $longitude
    ) {}

    public function getLatitude(): float
    {
        return $this->latitude;
    }

    public function getLongitude(): float
    {
        return $this->longitude;
    }

    public function __toString(): string
    {
        return sprintf('Lat/Long: %.6f, %.6f', $this->latitude, $this->longitude);
    }
}