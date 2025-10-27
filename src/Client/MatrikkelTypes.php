<?php
/**
 * Matrikkel SOAP Type Definitions
 * 
 * These classes enable PHP SOAP client to properly serialize/deserialize
 * complex Matrikkel API types using classmap.
 * 
 * IMPORTANT: Do NOT manually serialize these to XML!
 * Let PHP SoapClient handle it automatically via classmap.
 */

namespace Iaasen\Matrikkel\Client;

// Prevent double-loading if this file gets included multiple times
if (!class_exists('Iaasen\\Matrikkel\\Client\\MatrikkelBubbleId', false)) {

/**
 * Base class for all MatrikkelBubbleId types
 * Used for cursor-based pagination in NedlastningService
 */
class MatrikkelBubbleId
{
    /**
     * @var int The ID value (MatrikkelenhetId, PersonId, etc.)
     */
    public $value;
    
    /**
     * @var SnapshotVersion|null Optional snapshot version for historical data
     */
    public $snapshotVersion;
    
    public function __construct($value = null, $snapshotVersion = null)
    {
        $this->value = $value;
        $this->snapshotVersion = $snapshotVersion;
    }
}

/**
 * MatrikkelenhetId - specific type for Matrikkelenhet objects
 */
class MatrikkelenhetId extends MatrikkelBubbleId
{
}

/**
 * PersonId - specific type for Person objects
 */
class PersonId extends MatrikkelBubbleId
{
}

/**
 * BygningId - specific type for Bygning objects
 */
class BygningId extends MatrikkelBubbleId
{
}

/**
 * BruksenhetId - specific type for Bruksenhet objects
 */
class BruksenhetId extends MatrikkelBubbleId
{
}

/**
 * VegId - specific type for Veg objects
 */
class VegId extends MatrikkelBubbleId
{
}

/**
 * AdresseId - specific type for Adresse objects
 */
class AdresseId extends MatrikkelBubbleId
{
}

/**
 * KommuneId - specific type for Kommune objects
 */
class KommuneId extends MatrikkelBubbleId
{
}

/**
 * PersonIdent - Base class for person identification
 */
class PersonIdent
{
    // Abstract base class - use FysiskPersonIdent or JuridiskPersonIdent
}

/**
 * FysiskPersonIdent - Physical person identification (fødselsnummer)
 */
class FysiskPersonIdent extends PersonIdent
{
    public $fodselsnummer;
    
    public function __construct($fodselsnummer = null)
    {
        $this->fodselsnummer = $fodselsnummer;
    }
}

/**
 * JuridiskPersonIdent - Legal entity identification (organisasjonsnummer)
 */
class JuridiskPersonIdent extends PersonIdent
{
    public $organisasjonsnummer;
    
    public function __construct($organisasjonsnummer = null)
    {
        $this->organisasjonsnummer = $organisasjonsnummer;
    }
}

/**
 * SnapshotVersion - timestamp for versioning
 * CRITICAL: Always use "9999-01-01T00:00:00+01:00" for latest data
 * to avoid "historical data permission" errors
 */
class SnapshotVersion
{
    /**
     * @var string ISO 8601 timestamp
     */
    public $timestamp;
    
    public function __construct($timestamp = null)
    {
        // Default to future date (9999-01-01) for "latest" snapshot
        $this->timestamp = $timestamp ?? '9999-01-01T00:00:00+01:00';
    }
}

/**
 * MatrikkelContext - context for all SOAP calls
 * Contains locale, coordinate system, snapshot version, etc.
 */
class MatrikkelContext
{
    /**
     * @var string Locale (e.g. "no_NO")
     */
    public $locale = 'no_NO';
    
    /**
     * @var bool Use original coordinates
     */
    public $brukOriginaleKoordinater = false;
    
    /**
     * @var string System version
     */
    public $systemVersion = '1.0';
    
    /**
     * @var string Client identification
     */
    public $klientIdentifikasjon = 'BergenKommune-PHP-Client';
    
    /**
     * @var SnapshotVersion Snapshot version for data retrieval
     */
    public $snapshotVersion;
    
    public function __construct()
    {
        // Always use future date for latest data
        $this->snapshotVersion = new SnapshotVersion();
    }
}

/**
 * KoordinatsystemKodeId - coordinate system reference
 */
class KoordinatsystemKodeId
{
    /**
     * @var int Coordinate system code ID
     */
    public $value;
    
    public function __construct($value = null)
    {
        $this->value = $value;
    }
}

} // End class_exists check
