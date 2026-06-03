<?php

/**
 * Pipelinq BrpPersoon.
 *
 * Immutable, normalised representation of a person returned by a BRP lookup,
 * decoupled from the HaalCentraal wire format. It is produced by any
 * {@see \OCA\Pipelinq\Service\Bsn\BrpClientInterface} implementation so the rest
 * of the app never depends on the external API shape (ADR-019).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Bsn
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Bsn;

/**
 * Normalised BRP person value object (REQ-BSN-004).
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) A BRP person legitimately has
 *  many authentic attributes; modelling them as a flat immutable DTO is clearer
 *  than nesting and keeps the normalisation mapping in one place.
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.2
 */
final class BrpPersoon
{
    /**
     * Constructor.
     *
     * @param string               $bsnGemaskeerd   Masked BSN (`***45678*`); never the raw value.
     * @param string               $voornamen       Given names.
     * @param string               $geslachtsnaam   Family name.
     * @param string               $geboortedatum   Birth date (ISO date).
     * @param string               $geslacht        Gender (man|vrouw|onbekend).
     * @param string               $indicatieGeheim Secrecy indicator ("0"|"1").
     * @param array<string, mixed> $verblijfplaats  Address sub-object.
     * @param string               $bronsysteem     Source system identifier.
     * @param string|null          $voorletters     Initials.
     * @param string|null          $voorvoegsel     Name prefix (van, de,
     *                                              …).
     * @param string|null          $adellijkeTitel  Noble title.
     * @param string|null          $geboorteplaats  Place of birth.
     * @param string|null          $geboorteland    Country of birth.
     */
    public function __construct(
        public readonly string $bsnGemaskeerd,
        public readonly string $voornamen,
        public readonly string $geslachtsnaam,
        public readonly string $geboortedatum,
        public readonly string $geslacht,
        public readonly string $indicatieGeheim,
        public readonly array $verblijfplaats,
        public readonly string $bronsysteem='HaalCentraal-BRP-v2.0',
        public readonly ?string $voorletters=null,
        public readonly ?string $voorvoegsel=null,
        public readonly ?string $adellijkeTitel=null,
        public readonly ?string $geboorteplaats=null,
        public readonly ?string $geboorteland=null,
    ) {
    }//end __construct()

    /**
     * Whether this person carries a municipal secrecy indication.
     *
     * @return bool True when indicatieGeheim is "1".
     *
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.2
     */
    public function heeftGeheimhouding(): bool
    {
        return $this->indicatieGeheim === '1';
    }//end heeftGeheimhouding()

    /**
     * Serialise to the brpPersoon schema shape (no raw BSN; address included).
     *
     * The caller adds the lookup/contact/retention fields before persisting.
     *
     * @return array<string, mixed> The schema-shaped person data.
     *
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.2
     */
    public function toArray(): array
    {
        return [
            'bsnGemaskeerd'   => $this->bsnGemaskeerd,
            'voornamen'       => $this->voornamen,
            'voorletters'     => $this->voorletters,
            'voorvoegsel'     => $this->voorvoegsel,
            'geslachtsnaam'   => $this->geslachtsnaam,
            'adellijkeTitel'  => $this->adellijkeTitel,
            'geboortedatum'   => $this->geboortedatum,
            'geboorteplaats'  => $this->geboorteplaats,
            'geboorteland'    => $this->geboorteland,
            'geslacht'        => $this->geslacht,
            'verblijfplaats'  => $this->verblijfplaats,
            'indicatieGeheim' => $this->indicatieGeheim,
            'bronsysteem'     => $this->bronsysteem,
        ];
    }//end toArray()
}//end class
