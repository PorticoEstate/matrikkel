<?php
/**
 * KommuneTable - Database handler for matrikkel_kommuner table
 * 
 * Håndterer lagring av norske kommuner hentet fra Matrikkel API
 * via NedlastningServiceWS.
 * 
 * @author Sigurd Nes
 * @date 2025-10-07
 */

namespace Iaasen\Matrikkel\LocalDb;

class KommuneTable extends AbstractTable
{
    protected string $tableName = 'matrikkel_kommuner';
    
    /**
     * Insert en kommune-rad fra Matrikkel API
     * 
     * @param object $kommune Kommune-objektet fra SOAP API
     * @return void
     */
    public function insertRow(object $kommune): void
    {
        // Extract koordinatsystem kode
        $koordinatsystemKode = null;
        if (isset($kommune->koordinatsystemKodeId->kode)) {
            $koordinatsystemKode = $kommune->koordinatsystemKodeId->kode;
        } elseif (isset($kommune->koordinatsystemKodeId)) {
            $koordinatsystemKode = $this->extractValue($kommune->koordinatsystemKodeId);
        }
        
        // Extract senterpunkt coordinates
        $senterpunktNord = null;
        $senterpunktOst = null;
        if (isset($kommune->senterpunkt)) {
            $senterpunktNord = $kommune->senterpunkt->nord ?? null;
            $senterpunktOst = $kommune->senterpunkt->ost ?? null;
        } elseif (isset($kommune->representasjonspunkt->posisjon)) {
            $senterpunktNord = $kommune->representasjonspunkt->posisjon->nord ?? null;
            $senterpunktOst = $kommune->representasjonspunkt->posisjon->ost ?? null;
        }
        
        // Ekstraher data fra Kommune-objektet
        $this->adresseRows[] = [
            'kommunenummer' => (int) $this->extractKommunenummer($kommune),
            'kommunenavn' => $kommune->kommunenavn ?? null,
            'fylkesnummer' => $this->extractFylkesnummer($kommune),
            'fylkesnavn' => $this->extractFylkesnavn($kommune),
            'gyldig_til_dato' => $this->extractDate($kommune->gyldigTilDato ?? null),
            'koordinatsystem_kode' => $koordinatsystemKode,
            'eksklusiv_bruker' => $kommune->eksklusivBruker ?? null,
            'nedsatt_konsesjonsgrense' => isset($kommune->nedsattKonsesjonsgrense) ? 
                ($kommune->nedsattKonsesjonsgrense ? 't' : 'f') : 'f',
            'senterpunkt_nord' => $senterpunktNord,
            'senterpunkt_ost' => $senterpunktOst,
        ];
        
        $this->cachedRows++;
        
        // Flush hver 100 rader for effektivitet
        if ($this->cachedRows >= 100) {
            $this->flush();
        }
    }
    
    /**
     * Ekstraher kommunenummer fra Kommune-objektet
     * 
     * Kommunenummer kan være string eller int, og må håndteres riktig
     */
    private function extractKommunenummer(object $kommune): ?int
    {
        if (isset($kommune->kommunenummer)) {
            // Konverter til int (fjern ledende nuller)
            return (int) $kommune->kommunenummer;
        }
        
        return null;
    }
    
    /**
     * Ekstraher fylkesnummer fra Kommune-objektet
     * 
     * Fylkesnummer er typisk de to første sifrene i kommunenummer
     */
    private function extractFylkesnummer(object $kommune): ?int
    {
        if (isset($kommune->fylkeId)) {
            // Hvis fylkeId er et objekt med value
            if (is_object($kommune->fylkeId) && isset($kommune->fylkeId->value)) {
                return (int) $kommune->fylkeId->value;
            }
            // Hvis fylkeId er direkte verdi
            return (int) $kommune->fylkeId;
        }
        
        // Fallback: Beregn fra kommunenummer (første 2 siffer)
        $kommunenummer = $this->extractKommunenummer($kommune);
        if ($kommunenummer && strlen($kommunenummer) >= 2) {
            return (int) substr($kommunenummer, 0, 2);
        }
        
        return null;
    }
    
    /**
     * Ekstraher fylkesnavn hvis tilgjengelig
     */
    private function extractFylkesnavn(object $kommune): ?string
    {
        // Fylkesnavn er typisk ikke direkte tilgjengelig i Kommune-objektet
        // Men kan finnes via fylke-oppslag senere
        return null;
    }
    
    /**
     * Ekstraher dato fra LocalDate-objekt
     */
    private function extractDate($dateObject): ?string
    {
        if (!$dateObject) {
            return null;
        }
        
        // Hvis det er et LocalDate-objekt med year, month, day
        if (is_object($dateObject)) {
            if (isset($dateObject->year, $dateObject->month, $dateObject->day)) {
                return sprintf(
                    '%04d-%02d-%02d',
                    $dateObject->year,
                    $dateObject->month,
                    $dateObject->day
                );
            }
        }
        
        // Hvis det er en string, returner som er
        if (is_string($dateObject)) {
            return $dateObject;
        }
        
        return null;
    }
    
    /**
     * Ekstraher value fra objekt med value-property
     */
    private function extractValue($object): ?int
    {
        if (!$object) {
            return null;
        }
        
        if (is_object($object) && isset($object->value)) {
            return (int) $object->value;
        }
        
        if (is_numeric($object)) {
            return (int) $object;
        }
        
        return null;
    }
    
    /**
     * Hent alle kommuner fra databasen
     * 
     * @return array Liste med alle kommuner
     */
    public function getAllKommuner(): array
    {
        $sql = "SELECT * FROM {$this->tableName} ORDER BY kommunenummer";
        $statement = $this->dbAdapter->query($sql);
        $result = $statement->execute();
        
        $kommuner = [];
        foreach ($result as $row) {
            $kommuner[] = $row;
        }
        return $kommuner;
    }
    
    /**
     * Hent en kommune fra databasen basert på kommunenummer
     * 
     * @param string $kommunenummer Kommunenummer (f.eks. "0301")
     * @return array|null Kommune-data eller null hvis ikke funnet
     */
    public function getKommuneByNumber(string $kommunenummer): ?array
    {
        $sql = "SELECT * FROM {$this->tableName} WHERE kommunenummer = ?";
        $statement = $this->dbAdapter->query($sql);
        $result = $statement->execute([$kommunenummer]);
        
        foreach ($result as $row) {
            return $row; // Return første (og eneste) resultat
        }
        return null;
    }
    
    /**
     * Tell antall kommuner i databasen
     * 
     * @return int Antall kommuner
     */
    public function countKommuner(): int
    {
        $sql = "SELECT COUNT(*) as count FROM {$this->tableName}";
        $statement = $this->dbAdapter->query($sql);
        $result = $statement->execute();
        
        foreach ($result as $row) {
            return (int) $row['count'];
        }
        return 0;
    }
}
