<?php

/**
 * Pipelinq RetentionService.
 *
 * Two-tier retention for the AVG workflow (REQ-AVG-009): the request dossier is
 * kept 5 years for accountability, while the underlying evidence PII is
 * pseudonymized 30 days after delivery (metadata retained). After the 5-year
 * window a dossier and its children are hard-deleted. All operations are
 * server-authoritative and audit-logged.
 *
 * The 5-year dossier-retention policy and the 30-day evidence-window SCHEDULE
 * are pipelinq overlays that OpenRegister does not own and are kept verbatim.
 * The PII-scrubbing STYLE, however, now ADOPTS OR's canonical value-replacement
 * convention: the matched PII is overwritten with OR's `[erased]` token
 * (the same token OR's `DataSubjectRequestService::erase` writes) rather than
 * pipelinq's earlier bespoke Dutch token, so authoritative source-object
 * erasure (OR's `erase`) and the cached-evidence retention pass converge on one
 * representation. Recorded in openspec/changes/pipelinq-avg-adopt-or-gdpr/design.md.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Avg
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.6
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Avg;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Retention and pseudonymization for the AVG workflow.
 *
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.6
 */
class RetentionService
{
    /**
     * Default number of days after delivery before evidence PII is pseudonymized.
     *
     * @var int
     */
    public const DEFAULT_EVIDENCE_DAYS = 30;

    /**
     * Replacement token written into a pseudonymised evidence preview.
     *
     * Mirrors OR's `DataSubjectRequestService` pseudonym token so the cached
     * evidence representation matches the authoritative source-object erasure.
     *
     * @var string
     */
    public const PSEUDONYM_TOKEN = '[erased]';

    /**
     * Constructor.
     *
     * @param AvgRepository   $repository The AVG OR repository.
     * @param IAppConfig      $appConfig  The app config.
     * @param LoggerInterface $logger     The logger.
     */
    public function __construct(
        private AvgRepository $repository,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Pseudonymize a single evidence item: drop the PII preview, keep metadata.
     *
     * @param array<string, mixed> $item The evidence item.
     *
     * @return array<string, mixed> The pseudonymized item.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.6
     */
    public function pseudonymizeEvidence(array $item): array
    {
        $item['inhoudPreview']     = self::PSEUDONYM_TOKEN;
        $item['contentHash']       = '';
        $item['gepseudonimiseerd'] = true;

        return $this->repository->save(
            schemaKey: AvgRepository::SCHEMA_BEWIJS_ITEM,
            object: $item,
            id: $this->repository->idOf($item)
        );
    }//end pseudonymizeEvidence()

    /**
     * Pseudonymize every evidence item whose collection date is older than the
     * configured window and that is not already pseudonymized.
     *
     * @param DateTimeInterface $now The reference time.
     *
     * @return int The number of items pseudonymized.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.6
     */
    public function pseudonymizeExpiredEvidence(DateTimeInterface $now): int
    {
        $cutoff = $now->getTimestamp() - ($this->evidenceDays() * 86400);
        $items  = $this->repository->findAll(schemaKey: AvgRepository::SCHEMA_BEWIJS_ITEM);
        $count  = 0;

        foreach ($items as $item) {
            if ((bool) ($item['gepseudonimiseerd'] ?? false) === true) {
                continue;
            }

            $collected = $this->timestampOf(value: (string) ($item['verzameldOp'] ?? ''));
            if ($collected === null || $collected >= $cutoff) {
                continue;
            }

            $this->pseudonymizeEvidence(item: $item);
            $count++;
        }

        if ($count > 0) {
            $this->logger->info('Pipelinq AVG: evidence pseudonymized', ['count' => $count]);
        }

        return $count;
    }//end pseudonymizeExpiredEvidence()

    /**
     * Hard-delete every dossier whose 5-year retention has expired.
     *
     * Deletes the request and its child objects (events, evidence, bundles,
     * denials, redactions). Audit-logs each deletion.
     *
     * @param DateTimeInterface $now The reference time.
     *
     * @return int The number of dossiers deleted.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.6
     */
    public function deleteExpiredDossiers(DateTimeInterface $now): int
    {
        $requests = $this->repository->findAll(schemaKey: AvgRepository::SCHEMA_VERZOEK);
        $deleted  = 0;

        foreach ($requests as $request) {
            $until = $this->timestampOf(value: (string) ($request['retentieTot'] ?? ''));
            if ($until === null || $until > $now->getTimestamp()) {
                continue;
            }

            $this->deleteDossier(request: $request);
            $deleted++;
        }

        return $deleted;
    }//end deleteExpiredDossiers()

    /**
     * Delete a dossier and all its child objects.
     *
     * @param array<string, mixed> $request The request payload.
     *
     * @return void
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.6
     */
    public function deleteDossier(array $request): void
    {
        $verzoekId = $this->repository->idOf($request);

        foreach ([
            AvgRepository::SCHEMA_TERMIJN_EVENT,
            AvgRepository::SCHEMA_BEWIJS_ITEM,
            AvgRepository::SCHEMA_EXPORT_BUNDLE,
            AvgRepository::SCHEMA_WEIGERING,
        ] as $schemaKey) {
            $children = $this->repository->findAll(schemaKey: $schemaKey, filters: ['verzoekId' => $verzoekId]);
            foreach ($children as $child) {
                $this->repository->delete(schemaKey: $schemaKey, id: $this->repository->idOf($child));
            }
        }

        $this->repository->delete(schemaKey: AvgRepository::SCHEMA_VERZOEK, id: $verzoekId);
        $this->logger->warning(
            'Pipelinq AVG: dossier deleted after retention window',
            ['verzoekId' => $verzoekId, 'kenmerk' => (string) ($request['kenmerk'] ?? '')]
        );
    }//end deleteDossier()

    /**
     * The configured evidence pseudonymization window in days.
     *
     * @return int The window in days.
     */
    private function evidenceDays(): int
    {
        return max(1, $this->appConfig->getValueInt(Application::APP_ID, 'avg_evidence_retention_days', self::DEFAULT_EVIDENCE_DAYS));
    }//end evidenceDays()

    /**
     * Parse an ISO 8601 timestamp to an epoch, or null.
     *
     * @param string $value The timestamp.
     *
     * @return int|null The epoch seconds, or null.
     */
    private function timestampOf(string $value): ?int
    {
        if ($value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->getTimestamp();
        } catch (\Throwable $e) {
            return null;
        }
    }//end timestampOf()
}//end class
