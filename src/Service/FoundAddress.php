<?php

declare(strict_types=1);

namespace Iaasen\Matrikkel\Service;

/**
 * FoundAddress - Data transfer object for address lookup results
 * 
 * Contains all information about a found address including related
 * matrikkelenhet and bruksenheter if they were fetched.
 */
class FoundAddress
{
    public function __construct(
        public readonly int $vegadresseId,
        public readonly string $gatenavn,
        public readonly int $husnummer,
        public readonly ?string $bokstav,
        public readonly int $adressekode,
        public readonly int $vegId,
        public readonly object $vegadresseObject,
        public readonly ?object $matrikkelObject = null,
        public readonly array $bruksenhetObjects = [],
        public readonly array $owners = []  // Eierforhold with owner details
    ) {}
}
