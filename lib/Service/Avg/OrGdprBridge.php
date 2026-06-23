<?php

/**
 * Pipelinq OrGdprBridge.
 *
 * Thin adapter onto OpenRegister's canonical, RBAC + tenant scoped GDPR
 * data-subject-rights capability (`OCA\OpenRegister\Service\Gdpr\
 * DataSubjectRequestService` + `DataSubjectDeadline`). Pipelinq's AVG workflow
 * deliberately ADOPTS OR's generic EU/GDPR mechanics — the art-12(3) one-month
 * (+ two-month) deadline, NER-index data discovery, and legal-hold-aware
 * field-level pseudonymise erasure — instead of its earlier divergent NL
 * approximations (30/60-day deadline, BSN-filter discovery, named-field SHA-256
 * pseudonymise). This authorized behavioural change is recorded in
 * openspec/changes/pipelinq-avg-adopt-or-gdpr/design.md.
 *
 * The OR services are resolved lazily through the DI container (the same pattern
 * the AVG repository uses for `ObjectService`) so the app still loads when OR is
 * absent; every verb degrades to a safe empty/false result in that case rather
 * than throwing.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Avg
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
 * @spec openspec/changes/pipelinq-avg-adopt-or-gdpr/design.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Avg;

use DateTimeImmutable;
use DateTimeInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Adapter onto OpenRegister's canonical GDPR data-subject-rights capability.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wires only the OR container
 *  and a logger.
 *
 * @spec openspec/changes/pipelinq-avg-adopt-or-gdpr/design.md
 */
class OrGdprBridge
{
    /**
     * Fully-qualified name of OR's consumable data-subject-request service.
     *
     * @var string
     */
    public const OR_REQUEST_SERVICE = 'OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService';

    /**
     * Fully-qualified name of OR's pure EU art-12 deadline helper.
     *
     * @var string
     */
    public const OR_DEADLINE = 'OCA\OpenRegister\Service\Gdpr\DataSubjectDeadline';

    /**
     * OR erase mode: field-level pseudonymise (row retained, PII overwritten).
     *
     * Mirrors DataSubjectRequestService::ERASE_MODE_PSEUDONYMISE. Pipelinq always
     * erases in this mode so the underlying record survives — this is what keeps
     * the NL 7-year Boekhoudplicht booking retention intact while the PII is
     * removed.
     *
     * @var string
     */
    public const ERASE_MODE_PSEUDONYMISE = 'pseudonymise';

    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container (lazy OR resolve).
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Whether OR's GDPR capability is resolvable in this deployment.
     *
     * @return bool True when both OR GDPR services resolve.
     *
     * @spec openspec/changes/pipelinq-avg-adopt-or-gdpr/design.md
     */
    public function isAvailable(): bool
    {
        return ($this->requestService() !== null && $this->deadlineService() !== null);
    }//end isAvailable()

    /**
     * Compute the EU art-12(3) base deadline: receivedAt + one month.
     *
     * @param DateTimeInterface $receivedAt When the request was received.
     *
     * @return DateTimeImmutable The base legal deadline (received + 1 month).
     *
     * @spec openspec/changes/pipelinq-avg-adopt-or-gdpr/design.md
     */
    public function computeDueAt(DateTimeInterface $receivedAt): DateTimeImmutable
    {
        $deadline = $this->deadlineService();
        if ($deadline === null) {
            // OR-absent fallback: replicate the art-12 one-month maths locally.
            return DateTimeImmutable::createFromInterface($receivedAt)->modify('+1 month');
        }

        return $deadline->computeDueAt(receivedAt: $receivedAt);
    }//end computeDueAt()

    /**
     * Extend a deadline once by two months (EU art-12(3)).
     *
     * @param DateTimeInterface $dueAt The current base due date.
     *
     * @return DateTimeImmutable The extended deadline (base + 2 months).
     *
     * @spec openspec/changes/pipelinq-avg-adopt-or-gdpr/design.md
     */
    public function extend(DateTimeInterface $dueAt): DateTimeImmutable
    {
        $deadline = $this->deadlineService();
        if ($deadline === null) {
            return DateTimeImmutable::createFromInterface($dueAt)->modify('+2 months');
        }

        return $deadline->extend(dueAt: $dueAt);
    }//end extend()

    /**
     * Discover a subject's objects across registers via OR's NER index.
     *
     * @param string      $subjectId The subject identifier value (email/name/BSN).
     * @param string|null $type      Optional GdprEntity type filter.
     *
     * @return array<int, array{object: array<string, mixed>, gdprEntities: array<int, array<string, mixed>>}>
     *
     * @spec openspec/changes/pipelinq-avg-adopt-or-gdpr/design.md
     */
    public function findSubjectData(string $subjectId, ?string $type=null): array
    {
        $service = $this->requestService();
        if ($service === null) {
            return [];
        }

        try {
            return $service->findSubjectData(subjectId: $subjectId, type: $type);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq AVG: OR findSubjectData failed',
                ['exception' => $e->getMessage()]
            );
            return [];
        }
    }//end findSubjectData()

    /**
     * Assemble OR's portable access export for a subject (art-15 / art-20).
     *
     * @param string      $subjectId The subject identifier value.
     * @param string|null $type      Optional GdprEntity type filter.
     *
     * @return array<string, mixed> The export bundle, or an empty bundle when OR is absent.
     *
     * @spec openspec/changes/pipelinq-avg-adopt-or-gdpr/design.md
     */
    public function assembleAccessExport(string $subjectId, ?string $type=null): array
    {
        $service = $this->requestService();
        if ($service === null) {
            return ['subject' => $subjectId, 'type' => $type, 'objectCount' => 0, 'objects' => []];
        }

        try {
            return $service->assembleAccessExport(subjectId: $subjectId, type: $type);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq AVG: OR assembleAccessExport failed',
                ['exception' => $e->getMessage()]
            );
            return ['subject' => $subjectId, 'type' => $type, 'objectCount' => 0, 'objects' => []];
        }
    }//end assembleAccessExport()

    /**
     * Erase a subject's data via OR's legal-hold-aware field-level pseudonymise.
     *
     * Always uses the PSEUDONYMISE mode so the owning records are RETAINED (the
     * row survives, matching PII values become OR's `[erased]` token). Objects
     * under an active legal hold or in an immutable archival status are reported
     * back in the `held` bucket and never mutated — this is the mechanism that
     * preserves the NL Boekhoudplicht 7-year booking retention.
     *
     * @param string      $subjectId The subject identifier value.
     * @param string|null $type      Optional GdprEntity type filter.
     * @param bool        $dryRun    When true, report matches/holds without mutating.
     *
     * @return array<string, mixed> OR's erase summary (erased/held/failed buckets).
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     *
     * @spec openspec/changes/pipelinq-avg-adopt-or-gdpr/design.md
     */
    public function erase(string $subjectId, ?string $type=null, bool $dryRun=false): array
    {
        $service = $this->requestService();
        if ($service === null) {
            return ['subject' => $subjectId, 'matchedCount' => 0, 'erased' => [], 'held' => [], 'failed' => [], 'complete' => false];
        }

        try {
            return $service->erase(
                subjectId: $subjectId,
                type: $type,
                eraseMode: self::ERASE_MODE_PSEUDONYMISE,
                dryRun: $dryRun
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq AVG: OR erase failed',
                ['exception' => $e->getMessage()]
            );
            return ['subject' => $subjectId, 'matchedCount' => 0, 'erased' => [], 'held' => [], 'failed' => [], 'complete' => false];
        }
    }//end erase()

    /**
     * Resolve OR's data-subject-request service, or null when unavailable.
     *
     * @return object|null The DataSubjectRequestService, or null.
     */
    private function requestService(): ?object
    {
        try {
            return $this->container->get(self::OR_REQUEST_SERVICE);
        } catch (\Throwable $e) {
            return null;
        }
    }//end requestService()

    /**
     * Resolve OR's pure deadline helper, or null when unavailable.
     *
     * @return object|null The DataSubjectDeadline, or null.
     */
    private function deadlineService(): ?object
    {
        try {
            return $this->container->get(self::OR_DEADLINE);
        } catch (\Throwable $e) {
            return null;
        }
    }//end deadlineService()
}//end class
