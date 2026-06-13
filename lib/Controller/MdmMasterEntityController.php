<?php

/**
 * Pipelinq MdmMasterEntityController.
 *
 * Authenticated steward-facing reads for the Master Entity list / detail views
 * and the data-quality dashboard: list with filters, single-entity detail with
 * source-record lineage, and aggregate quality / sync-health metrics.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-007
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Mdm\MasterEntityService;
use OCA\Pipelinq\Service\Mdm\SyncQueueService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for Master Entity reads and the data-quality dashboard.
 */
class MdmMasterEntityController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest            $request        The request.
     * @param MasterEntityService $masterEntities The master-entity service.
     * @param SyncQueueService    $syncQueue      The sync queue service.
     * @param IUserSession        $userSession    The user session.
     * @param LoggerInterface     $logger         The logger.
     */
    public function __construct(
        IRequest $request,
        private MasterEntityService $masterEntities,
        private SyncQueueService $syncQueue,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List Master Entities with optional entityType / status / score filters.
     *
     * @return JSONResponse The entity list.
     *
     * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-001
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        $entityType = (string) $this->request->getParam('entityType', '');
        $status     = (string) $this->request->getParam('status', '');
        $maxScore   = $this->request->getParam('maxScore', null);

        try {
            $entities = $this->masterEntities->findAll(
                entityType: $this->nullIfEmpty(value: $entityType),
                status: $this->nullIfEmpty(value: $status)
            );
        } catch (\Throwable $e) {
            $this->logger->warning('MDM list failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(['message' => 'Could not list master entities'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        if ($maxScore !== null && $maxScore !== '') {
            $threshold = (float) $maxScore;
            $entities  = array_values(
                array_filter(
                    $entities,
                    static fn (array $e): bool => ((float) ($e['dataQualityScore'] ?? 1.0) <= $threshold)
                )
            );
        }

        return new JSONResponse(['entities' => $entities]);
    }//end index()

    /**
     * Master Entity detail with linked source records (lineage).
     *
     * @param string $id The master entity uuid.
     *
     * @return JSONResponse The entity plus its source records.
     *
     * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-001
     */
    #[NoAdminRequired]
    public function show(string $id): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $entity = $this->masterEntities->find($id);
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => 'Lookup failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        if ($entity === null) {
            return new JSONResponse(['message' => 'Master entity not found'], Http::STATUS_NOT_FOUND);
        }

        $sources = $this->masterEntities->linkedSourceRecords($id);

        return new JSONResponse(['entity' => $entity, 'sourceRecords' => $sources]);
    }//end show()

    /**
     * Aggregate data-quality + sync-health metrics for the dashboard.
     *
     * @return JSONResponse The dashboard metrics.
     *
     * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-007
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) The method tallies quality
     *  buckets and sync-queue health in two flat passes; the branches are simple
     *  counters, not nested decision logic.
     */
    #[NoAdminRequired]
    public function dashboard(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $entities = $this->masterEntities->findAll(null, 'active');
            $queue    = $this->syncQueue->listItems();
        } catch (\Throwable $e) {
            $this->logger->warning('MDM dashboard failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(['message' => 'Could not build dashboard'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        $buckets = ['good' => 0, 'fair' => 0, 'poor' => 0];
        $sum     = 0.0;
        $worst   = [];
        foreach ($entities as $entity) {
            $score = (float) ($entity['dataQualityScore'] ?? 0.0);
            $sum  += $score;

            $bucket = 'poor';
            if ($score > 0.8) {
                $bucket = 'good';
            } else if ($score >= 0.6) {
                $bucket = 'fair';
            }

            $buckets[$bucket]++;

            $worst[] = [
                'masterId'         => (string) ($entity['masterId'] ?? ($entity['id'] ?? '')),
                'entityType'       => (string) ($entity['entityType'] ?? ''),
                'name'             => (string) ($entity['goldenRecord']['name'] ?? ''),
                'dataQualityScore' => $score,
            ];
        }

        usort($worst, static fn (array $a, array $b): int => ($a['dataQualityScore'] <=> $b['dataQualityScore']));

        $queueHealth = ['queued' => 0, 'sending' => 0, 'acknowledged' => 0, 'dead-letter' => 0, 'failed' => 0];
        $deadLetters = [];
        foreach ($queue as $item) {
            $status = (string) ($item['status'] ?? '');
            if (isset($queueHealth[$status]) === true) {
                $queueHealth[$status]++;
            }

            if ($status === 'dead-letter') {
                $deadLetters[] = $item;
            }
        }

        $count        = count($entities);
        $averageScore = 0.0;
        if ($count > 0) {
            $averageScore = round(($sum / $count), 2);
        }

        return new JSONResponse(
            [
                'averageScore'  => $averageScore,
                'buckets'       => $buckets,
                'total'         => $count,
                'worstEntities' => array_slice($worst, 0, 10),
                'queueHealth'   => $queueHealth,
                'deadLetters'   => $deadLetters,
            ]
        );
    }//end dashboard()

    /**
     * Return null for an empty string, otherwise the value (filter helper).
     *
     * @param string $value The candidate value.
     *
     * @return string|null The value, or null when empty.
     */
    private function nullIfEmpty(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        return $value;
    }//end nullIfEmpty()
}//end class
