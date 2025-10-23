<?php
require __DIR__ . '/../vendor/autoload.php';

use Iaasen\Matrikkel\Service\PdoFactory;

$orgnr = $argv[1] ?? null;
$personId = $argv[2] ?? null;
if (!$orgnr && !$personId) {
    echo "Usage: php scripts/debug_org.php <organisasjonsnummer> [personId]\n";
    exit(1);
}

$pdo = PdoFactory::create();

if ($orgnr) {
    echo "Checking juridical person with organisasjonsnummer=$orgnr\n";
    $stmt = $pdo->prepare('SELECT * FROM matrikkel_juridiske_personer WHERE organisasjonsnummer = ?');
    $stmt->execute([$orgnr]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Found " . count($rows) . " rows in matrikkel_juridiske_personer:\n";
    foreach ($rows as $r) {
        print_r($r);
    }
}

if ($personId) {
    echo "\nChecking matrikkelenheter linked to person_id=$personId (as juridisk owner)\n";
    try {
        $stmt = $pdo->prepare('SELECT matrikkelenhet_id, kommunenummer, eier_type, eier_juridisk_person_id, eier_person_id FROM matrikkel_matrikkelenheter WHERE eier_juridisk_person_id = ? OR eier_person_id = ? LIMIT 50');
        $stmt->execute([$personId, $personId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "Found " . count($rows) . " matrikkelenheter referencing this person id:\n";
        foreach ($rows as $r) {
            print_r($r);
        }
    } catch (\PDOException $e) {
        echo "Query failed: " . $e->getMessage() . "\n";
        echo "Listing columns for matrikkel_matrikkelenheter:\n";
        $colStmt = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_name = 'matrikkel_matrikkelenheter'");
        $colStmt->execute();
        $cols = $colStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $c) {
            echo " - " . $c['column_name'] . "\n";
        }
    }
}

// Also check if any matrikkelenheter exist in kommune 4601 with any eier_juridisk_person_id
$stmt = $pdo->prepare('SELECT COUNT(*) as c FROM matrikkel_matrikkelenheter WHERE kommunenummer = ? AND eier_juridisk_person_id IS NOT NULL');
$stmt->execute([4601]);
$c = $stmt->fetch(PDO::FETCH_ASSOC);
echo "\nIn kommune 4601, matrikkelenheter with juridical owner: " . ($c['c'] ?? 0) . "\n";

echo "Done.\n";
