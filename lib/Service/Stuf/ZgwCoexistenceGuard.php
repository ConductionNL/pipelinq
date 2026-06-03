<?php

/**
 * Pipelinq ZgwCoexistenceGuard.
 *
 * Ensures the StUF adapter and the ZGW REST bridge never both register the same
 * Request as a zaak: a gemeente endpoint speaks ONE protocol per case at a time.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Stuf
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Stuf;

use Psr\Log\LoggerInterface;

/**
 * Coexistence guard between the StUF (SOAP) and ZGW (REST) zaak bridges.
 *
 * StUF and ZGW are sister modules that both maintain ZaaksysteemMapping rows but
 * speak different protocols. A single Request must not be registered as a zaak
 * over both transports simultaneously (that would create a duplicate case). This
 * guard inspects the existing mappings for a Request and reports whether a ZGW
 * (or already-StUF) registration is present, so the adapter can no-op rather
 * than create a clone. It mirrors the zgw-api-bridge ZgwCoexistenceValidator
 * contract so either side can defer to the other.
 */
class ZgwCoexistenceGuard
{
    /**
     * External entity types that represent an already-registered zaak.
     *
     * @var array<int, string>
     */
    private const ZAAK_ENTITEITEN = ['ZAK'];

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger The logger.
     */
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Decide whether a fresh StUF zaak registration is allowed for a Request.
     *
     * @param array<int, array<string, mixed>> $existingMappings The Request's existing ZaaksysteemMapping rows.
     *
     * @return bool True when no conflicting zaak registration exists yet.
     */
    public function mayRegisterZaak(array $existingMappings): bool
    {
        foreach ($existingMappings as $mapping) {
            $entiteit = (string) ($mapping['externEntiteit'] ?? '');
            $extId    = (string) ($mapping['externIdentificatie'] ?? '');
            if (in_array($entiteit, self::ZAAK_ENTITEITEN, true) === true && $extId !== '') {
                $this->logger->info(
                    'StUF zaak registration skipped: a zaak mapping already exists (ZGW/StUF coexistence)',
                    ['externIdentificatie' => $extId]
                );
                return false;
            }
        }

        return true;
    }//end mayRegisterZaak()

    /**
     * Resolve the external zaak identificatie already linked to a Request, if any.
     *
     * @param array<int, array<string, mixed>> $existingMappings The Request's existing mappings.
     *
     * @return string|null The existing zaak identificatie, or null when none.
     */
    public function existingZaakIdentificatie(array $existingMappings): ?string
    {
        foreach ($existingMappings as $mapping) {
            if (in_array((string) ($mapping['externEntiteit'] ?? ''), self::ZAAK_ENTITEITEN, true) === true) {
                $extId = (string) ($mapping['externIdentificatie'] ?? '');
                if ($extId !== '') {
                    return $extId;
                }
            }
        }

        return null;
    }//end existingZaakIdentificatie()
}//end class
