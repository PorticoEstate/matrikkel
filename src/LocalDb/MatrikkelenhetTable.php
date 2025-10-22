<?php
/**
 * Database table handler for matrikkel_matrikkelenheter
 * 
 * Håndterer lagring av matrikkelenheter med eierforhold fra Matrikkel API
 * 
 * @author Sigurd Nes
 * @date 2025-10-08
 */

namespace Iaasen\Matrikkel\LocalDb;

use Laminas\Db\Adapter\Adapter;

class MatrikkelenhetTable extends AbstractTable
{
    protected string $tableName = 'matrikkel_matrikkelenheter';
    
    public function __construct(Adapter $dbAdapter)
    {
        parent::__construct($dbAdapter);
    }
    
    /**
     * Insert a Matrikkelenhet object from SOAP API into the buffer
     * 
     * @param object $matrikkelenhet Matrikkelenhet SOAP object
     */
    public function insertRow(object $matrikkelenhet): void
    {
        // Extract matrikkelenhet_id from MatrikkelBubbleId
        $matrikkelenhetId = $this->extractId($matrikkelenhet);
        if (!$matrikkelenhetId) {
            return; // Skip if no valid ID
        }
        
        // Extract matrikkelnummer components
        $matrikkelnummer = $matrikkelenhet->matrikkelnummer ?? null;
        $kommunenummer = $this->extractKommunenummer($matrikkelnummer);
        $gardsnummer = $matrikkelnummer->gardsnummer ?? 0;
        $bruksnummer = $matrikkelnummer->bruksnummer ?? 0;
        $festenummer = $matrikkelnummer->festenummer ?? 0;
        $seksjonsnummer = $matrikkelnummer->seksjonsnummer ?? 0;
        
        // Build matrikkelnummer text (format: "kommunenr/gnr/bnr/fnr/snr")
        $matrikkelnummerTekst = sprintf(
            "%d/%d/%d/%d/%d",
            $kommunenummer,
            $gardsnummer,
            $bruksnummer,
            $festenummer,
            $seksjonsnummer
        );
        
        // Extract eierforhold (tinglyst eier)
        $eierInfo = $this->extractTinglystEier($matrikkelenhet);
        
        // Extract areal data
        $historiskOppgittAreal = isset($matrikkelenhet->historiskOppgittAreal) 
            ? (float)$matrikkelenhet->historiskOppgittAreal 
            : null;
        
        // Extract areal kilde
        $arealKilde = $this->extractArealKilde($matrikkelenhet);
        
        // Build row data
        $this->adresseRows[] = [
            'matrikkelenhet_id' => $matrikkelenhetId,
            'kommunenummer' => $kommunenummer,
            'gardsnummer' => $gardsnummer,
            'bruksnummer' => $bruksnummer,
            'festenummer' => $festenummer,
            'seksjonsnummer' => $seksjonsnummer,
            'matrikkelnummer_tekst' => $matrikkelnummerTekst,
            
            // NOTE: Eierforhold data is stored in separate matrikkel_eierforhold table
            // (removed eier_type, eier_person_id, eier_juridisk_person_id)
            
            // Areal og eiendominformasjon
            'historisk_oppgitt_areal' => $historiskOppgittAreal,
            'areal_kilde' => $arealKilde,
            'tinglyst' => isset($matrikkelenhet->tinglyst) ? ($matrikkelenhet->tinglyst ? 't' : 'f') : 'f',
            'skyld' => isset($matrikkelenhet->skyld) ? (float)$matrikkelenhet->skyld : null,
            'bruksnavn' => $this->extractScalar($matrikkelenhet->bruksnavn ?? null),
            
            // Datoer
            'etableringsdato' => $this->extractDate($matrikkelenhet->etableringsdato ?? null),
            
            // Status-flagg
            'er_seksjonert' => isset($matrikkelenhet->erSeksjonert) ? ($matrikkelenhet->erSeksjonert ? 't' : 'f') : 'f',
            'har_aktive_festegrunner' => isset($matrikkelenhet->harAktiveFestegrunner) ? ($matrikkelenhet->harAktiveFestegrunner ? 't' : 'f') : 'f',
            'har_anmerket_klage' => isset($matrikkelenhet->harAnmerketKlage) ? ($matrikkelenhet->harAnmerketKlage ? 't' : 'f') : 'f',
            'har_grunnforurensing' => isset($matrikkelenhet->harGrunnforurensing) ? ($matrikkelenhet->harGrunnforurensing ? 't' : 'f') : 'f',
            'har_kulturminne' => isset($matrikkelenhet->harKulturminne) ? ($matrikkelenhet->harKulturminne ? 't' : 'f') : 'f',
            'utgatt' => isset($matrikkelenhet->utgatt) ? ($matrikkelenhet->utgatt ? 't' : 'f') : 'f',
            'nymatrikulert' => isset($matrikkelenhet->nymatrikulert) ? ($matrikkelenhet->nymatrikulert ? 't' : 'f') : 'f',
        ];
        
        $this->cachedRows++;
        
        // Flush every 100 rows for efficiency
        if ($this->cachedRows >= 100) {
            $this->flush();
        }
    }
    
    /**
     * Extract matrikkelenhet_id from MatrikkelBubbleId
     */
    private function extractId(object $matrikkelenhet): ?int
    {
        if (isset($matrikkelenhet->id->value)) {
            return (int)$matrikkelenhet->id->value;
        }
        return null;
    }
    
    /**
     * Extract kommunenummer from Matrikkelnummer
     */
    private function extractKommunenummer(?object $matrikkelnummer): int
    {
        if (!$matrikkelnummer) {
            return 0;
        }
        
        // Extract from kommuneId if available
        if (isset($matrikkelnummer->kommuneId->value)) {
            return (int)$matrikkelnummer->kommuneId->value;
        }
        
        return 0;
    }
    
    /**
     * Extract tinglyst eier from matrikkelenhet
     * Returns array with eier type and ID (no full details - fetched on-demand)
     * 
     * Type detection: Check xsi:type attribute or class name hints in SOAP response
     * to distinguish Person vs JuridiskPerson IDs.
     */
    private function extractTinglystEier(object $matrikkelenhet): array
    {
        $result = [
            'type' => 'ukjent',
            'id' => null,
        ];
        
        // Check if eierforhold list exists
        if (!isset($matrikkelenhet->eierforhold) || !isset($matrikkelenhet->eierforhold->item)) {
            return $result;
        }
        
        $eierforholdList = is_array($matrikkelenhet->eierforhold->item) 
            ? $matrikkelenhet->eierforhold->item 
            : [$matrikkelenhet->eierforhold->item];
        
        // Find first eierforhold with eierId
        foreach ($eierforholdList as $eierforhold) {
            // Extract eierId if present
            if (isset($eierforhold->eierId->value)) {
                $result['id'] = (int)$eierforhold->eierId->value;
                
                // Try to detect type from class name or type hints
                // SOAP response often includes hints like "FysiskPerson" or "JuridiskPerson"
                $className = get_class($eierforhold);
                $typeName = isset($eierforhold->{'@xsi:type'}) ? $eierforhold->{'@xsi:type'} : '';
                
                if (stripos($className, 'JuridiskPerson') !== false || 
                    stripos($typeName, 'JuridiskPerson') !== false) {
                    $result['type'] = 'juridisk_person';
                } elseif (stripos($className, 'FysiskPerson') !== false || 
                          stripos($className, 'Person') !== false ||
                          stripos($typeName, 'Person') !== false) {
                    $result['type'] = 'person';
                }
                // else: remains 'ukjent' - will be resolved during eier fetch
                
                // Only take first eierforhold
                break;
            }
        }
        
        return $result;
    }    /**
     * Extract areal kilde description
     */
    private function extractArealKilde(object $matrikkelenhet): ?string
    {
        if (!isset($matrikkelenhet->historiskArealkildeId)) {
            return null;
        }
        
        $arealkildeId = $matrikkelenhet->historiskArealkildeId;
        
        // Extract description or code
        if (isset($arealkildeId->beskrivelse)) {
            return $arealkildeId->beskrivelse;
        }
        
        if (isset($arealkildeId->kode)) {
            return $arealkildeId->kode;
        }
        
        if (isset($arealkildeId->value)) {
            return (string)$arealkildeId->value;
        }
        
        return null;
    }
    
    /**
     * Convert LocalDate to SQL date string
     */
    private function extractDate(?object $localDate): ?string
    {
        if (!$localDate) {
            return null;
        }
        
        // LocalDate has: year, month, day properties
        if (isset($localDate->year, $localDate->month, $localDate->day)) {
            return sprintf(
                '%04d-%02d-%02d',
                $localDate->year,
                $localDate->month,
                $localDate->day
            );
        }
        
        return null;
    }
    
    /**
     * Extract scalar value from mixed input
     * Handles empty objects from json_decode(json_encode(simplexml))
     */
    private function extractScalar($value): ?string
    {
        if ($value === null) {
            return null;
        }
        
        // If it's an object or array, it's probably empty - return null
        if (is_object($value) || is_array($value)) {
            return null;
        }
        
        // Return as string
        return (string)$value;
    }
}
