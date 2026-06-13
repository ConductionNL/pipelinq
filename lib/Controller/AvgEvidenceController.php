<?php

/**
 * Pipelinq AvgEvidenceController.
 *
 * REST controller for AVG evidence collection: triggering a federated collection
 * run, reading the collection status, and listing the collected BewijsItems with
 * deduplication flagging. Access is scoped through AvgRequestService so a handler
 * can only collect for their own requests (IDOR-safe).
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Avg\AvgRepository;
use OCA\Pipelinq\Service\Avg\AvgRequestService;
use OCA\Pipelinq\Service\Avg\EvidenceCollectionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for AVG evidence-collection endpoints.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.2
 */
class AvgEvidenceController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                  $request     The request.
     * @param AvgRequestService         $requests    The request lifecycle service.
     * @param EvidenceCollectionService $collection  The evidence collection service.
     * @param AvgRepository             $repository  The AVG OR repository.
     * @param IUserSession              $userSession The user session.
     * @param IL10N                     $l10n        The localization service.
     * @param LoggerInterface           $logger      The logger.
     */
    public function __construct(
        IRequest $request,
        private AvgRequestService $requests,
        private EvidenceCollectionService $collection,
        private AvgRepository $repository,
        private IUserSession $userSession,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Run a federated evidence-collection pass and return the summary.
     *
     * The collection itself is bounded and synchronous here; the background job
     * (CollectEvidenceJob) drives long-running asynchronous passes. Both go
     * through the same service so the behaviour is identical.
     *
     * @param string $id The request UUID.
     *
     * @return JSONResponse The collection summary.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.2
     */
    #[NoAdminRequired]
    public function collect(string $id): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        return $this->run(
            action: function () use ($id, $uid): array {
                $request = $this->requests->get(id: $id, userId: $uid);
                return ['summary' => $this->collection->collect(request: $request)];
            },
            label: 'collect'
        );
    }//end collect()

    /**
     * Report the evidence-collection status for a request.
     *
     * @param string $id The request UUID.
     *
     * @return JSONResponse The status counts.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.2
     */
    #[NoAdminRequired]
    public function status(string $id): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        return $this->run(
            action: function () use ($id, $uid): array {
                $this->requests->get(id: $id, userId: $uid);
                $items = $this->repository->findAll(
                    schemaKey: AvgRepository::SCHEMA_BEWIJS_ITEM,
                    filters: ['verzoekId' => $id]
                );

                $collected = 0;
                $failed    = 0;
                foreach ($items as $item) {
                    if ((string) ($item['categorie'] ?? '') === 'bron-onbereikbaar') {
                        $failed++;
                        continue;
                    }

                    $collected++;
                }

                return ['status' => ['collected' => $collected, 'failed' => $failed, 'total' => count($items)]];
            },
            label: 'status'
        );
    }//end status()

    /**
     * List the collected evidence items for a request.
     *
     * @param string $id The request UUID.
     *
     * @return JSONResponse The evidence items.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.2
     */
    #[NoAdminRequired]
    public function items(string $id): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        return $this->run(
            action: function () use ($id, $uid): array {
                $this->requests->get(id: $id, userId: $uid);
                return [
                    'bewijsItems' => $this->repository->findAll(
                        schemaKey: AvgRepository::SCHEMA_BEWIJS_ITEM,
                        filters: ['verzoekId' => $id]
                    ),
                ];
            },
            label: 'items'
        );
    }//end items()

    /**
     * Require an authenticated user, returning their UID or a 401.
     *
     * @return string|JSONResponse The acting user UID, or a 401 response.
     */
    private function requireUserId(): string|JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Authentication required')],
                Http::STATUS_UNAUTHORIZED
            );
        }

        return $user->getUID();
    }//end requireUserId()

    /**
     * Run an action with shared OCS-to-HTTP error mapping.
     *
     * @param callable $action The action to run.
     * @param string   $label  A short label for log context.
     *
     * @return JSONResponse The response.
     */
    private function run(callable $action, string $label): JSONResponse
    {
        try {
            return new JSONResponse($action());
        } catch (OCSNotFoundException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (OCSForbiddenException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (OCSBadRequestException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            $this->logger->error('AvgEvidenceController::'.$label.' failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => $this->l10n->t('An unexpected error occurred')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end run()
}//end class
