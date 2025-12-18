#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;

// Load .env file
$dotenv = new Dotenv();
$dotenv->loadEnv(__DIR__ . '/../.env');

$bygningNummer = $argv[1] ?? null;

if (!$bygningNummer) {
    echo "Usage: php debug_bygning_address.php <matrikkel_bygning_nummer>\n";
    exit(1);
}

// Connect using .env configuration
$host = $_ENV['DB_HOST'] ?? 'localhost';
$port = $_ENV['DB_PORT'] ?? '5432';
$dbname = $_ENV['DB_NAME'] ?? 'matrikkel';
$user = $_ENV['DB_USERNAME'] ?? 'matrikkel';
$password = $_ENV['DB_PASSWORD'] ?? 'matrikkel';

$dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
$pdo = new PDO($dsn, $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Find bygning
echo "=== BYGNING {$bygningNummer} ===\n";
$stmt = $pdo->prepare('SELECT bygning_id, matrikkel_bygning_nummer, kommunenummer, lopenummer_i_eiendom, lokasjonskode_bygg FROM matrikkel_bygninger WHERE matrikkel_bygning_nummer = ?');
$stmt->execute([$bygningNummer]);
$bygning = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$bygning) {
    echo "Bygning not found\n";
    exit(1);
}

print_r($bygning);
$bygningId = $bygning['bygning_id'];

// Find bruksenheter
echo "\n=== BRUKSENHETER FOR BYGNING {$bygningId} ===\n";
$stmt = $pdo->prepare('SELECT bruksenhet_id, matrikkelenhet_id, adresse_id, inngang_id, lopenummer_i_inngang, lokasjonskode_bruksenhet FROM matrikkel_bruksenheter WHERE bygning_id = ?');
$stmt->execute([$bygningId]);
$bruksenheter = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Found " . count($bruksenheter) . " bruksenheter\n";
foreach ($bruksenheter as $br) {
    echo "  Bruksenhet {$br['bruksenhet_id']}: adresse_id={$br['adresse_id']}, matrikkelenhet_id={$br['matrikkelenhet_id']}, inngang_id={$br['inngang_id']}, lokasjonskode={$br['lokasjonskode_bruksenhet']}\n";
}

// Find matrikkelenheter connected to this bygning
echo "\n=== MATRIKKELENHETER (via bygning_matrikkelenhet) ===\n";
$stmt = $pdo->prepare('SELECT DISTINCT me.matrikkelenhet_id, me.matrikkelnummer_tekst, me.lokasjonskode_eiendom FROM matrikkel_bygning_matrikkelenhet bm JOIN matrikkel_matrikkelenheter me ON bm.matrikkelenhet_id = me.matrikkelenhet_id WHERE bm.bygning_id = ?');
$stmt->execute([$bygningId]);
$matrikkelenheter = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($matrikkelenheter as $me) {
    echo "  Matrikkelenhet {$me['matrikkelenhet_id']}: {$me['matrikkelnummer_tekst']}, lokasjonskode={$me['lokasjonskode_eiendom']}\n";
}

// Find addresses via matrikkelenhet_adresse table
echo "\n=== ADRESSER (via matrikkelenhet_adresse) ===\n";
$stmt = $pdo->prepare('
    SELECT DISTINCT a.adresse_id, va.nummer as husnummer, va.bokstav, v.adressenavn, v.adressekode, v.veg_id
    FROM matrikkel_bygning_matrikkelenhet bm
    JOIN matrikkel_matrikkelenhet_adresse ma ON bm.matrikkelenhet_id = ma.matrikkelenhet_id
    JOIN matrikkel_adresser a ON ma.adresse_id = a.adresse_id
    LEFT JOIN matrikkel_vegadresser va ON a.adresse_id = va.vegadresse_id
    LEFT JOIN matrikkel_veger v ON va.veg_id = v.veg_id
    WHERE bm.bygning_id = ?
');
$stmt->execute([$bygningId]);
$adresser = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Found " . count($adresser) . " addresses\n";
foreach ($adresser as $a) {
    echo "  Adresse {$a['adresse_id']}: {$a['adressenavn']} {$a['husnummer']}{$a['bokstav']}, veg_id={$a['veg_id']}, adressekode={$a['adressekode']}\n";
}

// Find innganger
echo "\n=== INNGANGER FOR BYGNING {$bygningId} ===\n";
$stmt = $pdo->prepare('SELECT inngang_id, bygning_id, matrikkel_bygning_nummer, kommunenummer, veg_id, husnummer, bokstav, adressekode, lopenummer_i_bygg, lokasjonskode_inngang FROM matrikkel_innganger WHERE bygning_id = ?');
$stmt->execute([$bygningId]);
$innganger = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Found " . count($innganger) . " innganger\n";
foreach ($innganger as $i) {
    echo "  Inngang {$i['inngang_id']}: matrikkel_bygning_nummer={$i['matrikkel_bygning_nummer']}, kommune={$i['kommunenummer']}, veg_id={$i['veg_id']}, husnummer={$i['husnummer']}, bokstav={$i['bokstav']}, adressekode={$i['adressekode']}, lopenummer={$i['lopenummer_i_bygg']}, lokasjonskode={$i['lokasjonskode_inngang']}\n";
    
    // Show veg details for this inngang
    if ($i['veg_id']) {
        $vegStmt = $pdo->prepare('SELECT adressenavn, adressekode FROM matrikkel_veger WHERE veg_id = ?');
        $vegStmt->execute([$i['veg_id']]);
        $veg = $vegStmt->fetch(PDO::FETCH_ASSOC);
        if ($veg) {
            echo "    -> Veg: {$veg['adressenavn']} (adressekode: {$veg['adressekode']})\n";
        }
    }
}

// Check if bruksenheter have addresses directly
echo "\n=== BRUKSENHETER WITH ADDRESSES ===\n";
$stmt = $pdo->prepare('
    SELECT br.bruksenhet_id, br.adresse_id, va.nummer as husnummer, va.bokstav, v.adressenavn
    FROM matrikkel_bruksenheter br
    LEFT JOIN matrikkel_adresser a ON br.adresse_id = a.adresse_id
    LEFT JOIN matrikkel_vegadresser va ON a.adresse_id = va.vegadresse_id
    LEFT JOIN matrikkel_veger v ON va.veg_id = v.veg_id
    WHERE br.bygning_id = ?
');
$stmt->execute([$bygningId]);
$brWithAddr = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($brWithAddr as $br) {
    echo "  Bruksenhet {$br['bruksenhet_id']}: adresse_id={$br['adresse_id']}, {$br['adressenavn']} {$br['husnummer']}{$br['bokstav']}\n";
}
