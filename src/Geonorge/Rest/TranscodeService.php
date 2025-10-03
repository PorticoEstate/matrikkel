<?php
/**
 * Temporary stub for TranscodeService to prevent class not found errors.
 * This provides basic functionality until the proper Geonorge package is available.
 */

namespace Iaasen\Geonorge\Rest;

use Iaasen\Geonorge\Entity\LocationLatLong;
use Iaasen\Geonorge\Entity\LocationUtm;

class TranscodeService
{
    /**
     * Convert coordinates from one UTM zone to another
     * NOTE: This is a stub implementation that returns coordinates unchanged
     */
    public function transcodeUtmZoneToUtmZone(float $north, float $east, int $fromZone, int $toZone): LocationUtm
    {
        // Stub implementation - for proper coordinate transformation, 
        // you would need to implement the full coordinate conversion logic
        return new LocationUtm($north, $east, $toZone . 'N');
    }

    /**
     * Convert Latitude/Longitude to UTM coordinates
     * NOTE: This is a stub implementation that provides approximated values
     */
    public function transcodeLatLongToUTM(float $lat, float $lon, int $zone = 32): LocationUtm
    {
        // Stub implementation - for proper coordinate transformation,
        // you would need to implement the full coordinate conversion logic
        // This is a very rough approximation for Norwegian coordinates
        
        // Rough conversion for Norway (zone 32-35)
        $east = ($lon - ($zone * 6 - 183)) * 111320 * cos(deg2rad($lat));
        $north = $lat * 111320;
        
        return new LocationUtm($north, $east, $zone . 'N');
    }

    /**
     * Convert UTM coordinates to Latitude/Longitude
     * NOTE: This is a stub implementation that provides approximated values
     */
    public function transcodeUTMtoLatLong(float $north, float $east, int $zone): LocationLatLong
    {
        // Stub implementation - for proper coordinate transformation,
        // you would need to implement the full coordinate conversion logic
        // This is a very rough approximation for Norwegian coordinates
        
        $lat = $north / 111320;
        $lon = ($east / (111320 * cos(deg2rad($lat)))) + ($zone * 6 - 183);
        
        return new LocationLatLong($lat, $lon);
    }
}