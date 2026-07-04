<?php

/**
 * Pipelinq PipelinqApRegulatorEscalateProvider.
 *
 * Phase-3 (ADR-047) regulator-escalate seam binding: pipelinq's NL AP
 * (Autoriteit Persoonsgegevens) implementation of OpenRegister's
 * `RegulatorEscalateProvider` contract. Registered into OR's
 * `RegulatorEscalateRegistry` at pipelinq boot (ADR-019), so OR's generic
 * data-subject-request case engine resolves it via the NL policy pack's
 * `regulatorEscalateProvider` selector (`pipelinq-ap-complaint`).
 *
 * It routes an escalation into the AP-complaint dossier: it assembles a
 * jurisdiction-stable AP reference from the case kenmerk/uuid and returns it
 * alongside the official AP klacht (complaint) route. This preserves the
 * mandatory AP-complaint reference the retired local denial/notification flow
 * used to carry. Escalation is FAIL-CLOSED: when the case carries no usable
 * identifier the outcome is `refused` (escalation NOT performed), never a
 * silent success (ADR-005 / CWE-863).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Gdpr
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/avg-consume-or-workflow/specs/avg-or-seam-bindings/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Gdpr;

use OCA\OpenRegister\Service\Gdpr\Regulator\RegulatorEscalateProvider;
use OCA\OpenRegister\Service\Gdpr\Regulator\RegulatorEscalateResult;
use Psr\Log\LoggerInterface;

/**
 * NL AP-complaint regulator-escalate provider bound into OR's DSAR case engine.
 *
 * @psalm-suppress UndefinedClass       OR's Gdpr seam classes are provided at
 *         runtime by the openregister app; static analysis runs without OR.
 * @psalm-suppress MissingDependency    Same — the OR interface is resolved at
 *         boot on a gated install only.
 *
 * @spec openspec/changes/avg-consume-or-workflow/specs/avg-or-seam-bindings/spec.md
 */
class PipelinqApRegulatorEscalateProvider implements RegulatorEscalateProvider
{
    /**
     * Stable provider id — the single source of truth for the value the NL
     * policy pack's `regulatorEscalateProvider` selector must name.
     *
     * @var string
     */
    public const PROVIDER_ID = 'pipelinq-ap-complaint';

    /**
     * The official Autoriteit Persoonsgegevens complaint (klacht) route.
     *
     * @var string
     */
    public const AP_COMPLAINT_URL = 'https://autoriteitpersoonsgegevens.nl/nl/zelf-doen/gebruik-uw-privacyrechten/klacht-melden-bij-de-ap';

    /**
     * Case payload keys probed for a human case reference, in order.
     *
     * @var array<int, string>
     */
    private const REFERENCE_KEYS = [
        'kenmerk',
        'reference',
        'caseNumber',
        'dossierNummer',
    ];

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger The logger.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * The stable provider id addressed by the NL pack selector.
     *
     * @return string
     *
     * @spec openspec/changes/avg-consume-or-workflow/specs/avg-or-seam-bindings/spec.md
     */
    public function getProviderId(): string
    {
        return self::PROVIDER_ID;
    }//end getProviderId()

    /**
     * Escalate a DSAR case to the Autoriteit Persoonsgegevens (AP).
     *
     * Builds a jurisdiction-stable AP-complaint reference from the case kenmerk
     * (falling back to the case uuid) and returns it with the official AP klacht
     * route. Fail-closed: with no usable identifier the outcome is `refused`.
     *
     * @param string               $caseUuid The DSAR case object uuid.
     * @param array<string, mixed> $case     The case's serialised payload.
     *
     * @return RegulatorEscalateResult The escalation outcome (reference + status).
     *
     * @SuppressWarnings(PHPMD.StaticAccess) OR's RegulatorEscalateResult is a value
     *  object constructed only through its named static factories.
     *
     * @spec openspec/changes/avg-consume-or-workflow/specs/avg-or-seam-bindings/spec.md
     */
    public function escalate(string $caseUuid, array $case): RegulatorEscalateResult
    {
        $kenmerk = $this->extractReference(case: $case);
        if ($kenmerk === null) {
            $kenmerk = trim($caseUuid);
        }

        if ($kenmerk === '') {
            return RegulatorEscalateResult::refused(
                providerId: self::PROVIDER_ID,
                message: 'Case carries no kenmerk or uuid; AP-complaint escalation not performed (fail-closed).'
            );
        }

        $reference = 'AP-KLACHT/'.$kenmerk;
        $this->logger->info(
            'Pipelinq regulator escalate: AP-complaint reference issued',
            ['case' => $caseUuid, 'reference' => $reference]
        );

        return RegulatorEscalateResult::escalated(
            providerId: self::PROVIDER_ID,
            reference: $reference,
            message: 'AP-complaint dossier prepared. Klacht melden bij de AP via '.self::AP_COMPLAINT_URL
        );
    }//end escalate()

    /**
     * Extract a human case reference from the case payload.
     *
     * @param array<string, mixed> $case The case's serialised payload.
     *
     * @return string|null The reference, or null when none is present.
     */
    private function extractReference(array $case): ?string
    {
        foreach (self::REFERENCE_KEYS as $key) {
            $value = ($case[$key] ?? null);
            if (is_string($value) === false && is_int($value) === false) {
                continue;
            }

            $candidate = trim((string) $value);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }//end extractReference()
}//end class
