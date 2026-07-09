<?php

/**
 * Test stub for OCA\OpenRegister\Service\Gdpr\Regulator\RegulatorEscalateProvider.
 *
 * Mirrors the interface signature shipped by openregister (dsar-integration-seams).
 * Used only where the openregister runtime is absent (bare CI containers).
 * Loaded via Composer's autoload-dev PSR-4 mapping. NOT scanned by PHPCS.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Stubs\Service\Gdpr\Regulator
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr\Regulator;

/**
 * Stub of OR's registerable regulator-escalation provider.
 */
interface RegulatorEscalateProvider
{
    /**
     * @return string
     */
    public function getProviderId(): string;

    /**
     * @param string               $caseUuid The case object uuid.
     * @param array<string, mixed> $case     The case's serialised payload.
     *
     * @return RegulatorEscalateResult
     */
    public function escalate(string $caseUuid, array $case): RegulatorEscalateResult;
}
