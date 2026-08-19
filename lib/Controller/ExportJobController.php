<?php

/**
 * Pipelinq ExportJobController.
 *
 * REST API for BI export destinations and jobs: CRUD, connection test, test
 * run, and enable/disable. Every endpoint is gated to the export-analyst group
 * or a Nextcloud admin via ExportAccessPolicy (ADR-005); all business logic and
 * validation live in the export services. Schema-object CRUD is otherwise
 * handled by OpenRegister's generic object API — these are the operations the
 * generic API cannot express (validation, connectivity, test run, scheduling).
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-001
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-002
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Export\ExportAccessPolicy;
use OCA\Pipelinq\Service\Export\ExportDestinationService;
use OCA\Pipelinq\Service\Export\ExportJobService;
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
 * Controller for export destination + job management endpoints.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)   Thin CRUD + action surface for
 *  two related resources (destinations and jobs); each method delegates to a
 *  service and shares one error-handling helper.
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-001
 */
class ExportJobController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                 $request      The request.
     * @param ExportJobService         $jobs         The job service.
     * @param ExportDestinationService $destinations The destination service.
     * @param ExportAccessPolicy       $policy       The export access policy.
     * @param IUserSession             $userSession  The user session.
     * @param IL10N                    $l10n         The localization service.
     * @param LoggerInterface          $logger       The logger.
     */
    public function __construct(
        IRequest $request,
        private ExportJobService $jobs,
        private ExportDestinationService $destinations,
        private ExportAccessPolicy $policy,
        private IUserSession $userSession,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List export destinations.
     *
     * @return JSONResponse The destinations.
     *
     * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-001
     */
    #[NoAdminRequired]
    public function listDestinations(): JSONResponse
    {
        return $this->requireExportAdmin(
            action: fn (): array => ['destinations' => $this->destinations->listDestinations()],
            label: 'listDestinations'
        );
    }//end listDestinations()

    /**
     * Create an export destination.
     *
     * @return JSONResponse The created destination.
     *
     * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-001-01
     */
    #[NoAdminRequired]
    public function createDestination(): JSONResponse
    {
        $data = $this->bodyParams();
        return $this->requireExportAdmin(
            action: fn (): array => ['destination' => $this->destinations->createDestination(data: $data)],
            label: 'createDestination'
        );
    }//end createDestination()

    /**
     * Update an export destination.
     *
     * @param string $id The destination UUID.
     *
     * @return JSONResponse The updated destination.
     */
    #[NoAdminRequired]
    public function updateDestination(string $id): JSONResponse
    {
        $data = $this->bodyParams();
        return $this->requireExportAdmin(
            action: fn (): array => ['destination' => $this->destinations->updateDestination(id: $id, data: $data)],
            label: 'updateDestination'
        );
    }//end updateDestination()

    /**
     * Delete an export destination.
     *
     * @param string $id The destination UUID.
     *
     * @return JSONResponse The deletion result.
     */
    #[NoAdminRequired]
    public function deleteDestination(string $id): JSONResponse
    {
        return $this->requireExportAdmin(
            action: function () use ($id): array {
                $this->destinations->deleteDestination(id: $id);
                return ['deleted' => true];
            },
            label: 'deleteDestination'
        );
    }//end deleteDestination()

    /**
     * Test connectivity to a destination.
     *
     * @param string $id The destination UUID.
     *
     * @return JSONResponse The test result.
     *
     * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-001-02
     */
    #[NoAdminRequired]
    public function testDestination(string $id): JSONResponse
    {
        return $this->requireExportAdmin(
            action: fn (): array => ['valid' => $this->destinations->testConnection(id: $id)],
            label: 'testDestination'
        );
    }//end testDestination()

    /**
     * List export jobs.
     *
     * @return JSONResponse The jobs.
     *
     * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-002
     */
    #[NoAdminRequired]
    public function listJobs(): JSONResponse
    {
        return $this->requireExportAdmin(action: fn (): array => ['jobs' => $this->jobs->listJobs()], label: 'listJobs');
    }//end listJobs()

    /**
     * Get one export job.
     *
     * @param string $id The job UUID.
     *
     * @return JSONResponse The job.
     */
    #[NoAdminRequired]
    public function showJob(string $id): JSONResponse
    {
        return $this->requireExportAdmin(action: fn (): array => ['job' => $this->jobs->getJob(id: $id)], label: 'showJob');
    }//end showJob()

    /**
     * Create an export job (disabled until tested + enabled).
     *
     * @return JSONResponse The created job.
     *
     * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-002-01
     */
    #[NoAdminRequired]
    public function createJob(): JSONResponse
    {
        $data = $this->bodyParams();
        $uid  = $this->actingUser();

        return $this->requireExportAdmin(
            action: fn (): array => ['job' => $this->jobs->createJob(data: $data, userId: $uid)],
            label: 'createJob'
        );
    }//end createJob()

    /**
     * Update an export job.
     *
     * @param string $id The job UUID.
     *
     * @return JSONResponse The updated job.
     */
    #[NoAdminRequired]
    public function updateJob(string $id): JSONResponse
    {
        $data = $this->bodyParams();
        return $this->requireExportAdmin(
            action: fn (): array => ['job' => $this->jobs->updateJob(id: $id, data: $data)],
            label: 'updateJob'
        );
    }//end updateJob()

    /**
     * Delete an export job.
     *
     * @param string $id The job UUID.
     *
     * @return JSONResponse The deletion result.
     */
    #[NoAdminRequired]
    public function deleteJob(string $id): JSONResponse
    {
        return $this->requireExportAdmin(
            action: function () use ($id): array {
                $this->jobs->deleteJob(id: $id);
                return ['deleted' => true];
            },
            label: 'deleteJob'
        );
    }//end deleteJob()

    /**
     * Run a non-destructive test of a job.
     *
     * @param string $id The job UUID.
     *
     * @return JSONResponse The test result (sample, errors).
     *
     * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-003-01
     */
    #[NoAdminRequired]
    public function testRun(string $id): JSONResponse
    {
        return $this->requireExportAdmin(
            action: function () use ($id): array {
                $job = $this->jobs->getJob(id: $id);
                return ['result' => $this->jobs->testRun(job: $job)];
            },
            label: 'testRun'
        );
    }//end testRun()

    /**
     * Enable a job for scheduled execution.
     *
     * @param string $id The job UUID.
     *
     * @return JSONResponse The enabled job.
     *
     * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-002
     */
    #[NoAdminRequired]
    public function enableJob(string $id): JSONResponse
    {
        return $this->requireExportAdmin(action: fn (): array => ['job' => $this->jobs->enableJob(id: $id)], label: 'enableJob');
    }//end enableJob()

    /**
     * Disable a job.
     *
     * @param string $id The job UUID.
     *
     * @return JSONResponse The disabled job.
     */
    #[NoAdminRequired]
    public function disableJob(string $id): JSONResponse
    {
        return $this->requireExportAdmin(action: fn (): array => ['job' => $this->jobs->disableJob(id: $id)], label: 'disableJob');
    }//end disableJob()

    /**
     * The acting user UID (empty when no session).
     *
     * @return string The UID.
     */
    private function actingUser(): string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return '';
        }

        return $user->getUID();
    }//end actingUser()

    /**
     * Decode the JSON / form request body into an array.
     *
     * @return array<string, mixed> The body parameters.
     */
    private function bodyParams(): array
    {
        $params = $this->request->getParams();
        unset($params['id'], $params['_route']);

        return $params;
    }//end bodyParams()

    /**
     * Require export-admin / analyst authorization, then run the action with
     * shared error handling.
     *
     * Returns 401 when unauthenticated, 403 when not an export admin/analyst,
     * 404 / 422 from the service's OCS exceptions, 500 otherwise.
     *
     * @param callable $action The action.
     * @param string   $label  Log label.
     *
     * @return JSONResponse The response.
     */
    private function requireExportAdmin(callable $action, string $label): JSONResponse
    {
        $uid = $this->actingUser();
        if ($uid === '') {
            return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
        }

        if ($this->policy->isExportAdmin(userId: $uid) === false) {
            return new JSONResponse(['error' => $this->l10n->t('Insufficient permissions')], Http::STATUS_FORBIDDEN);
        }

        try {
            return new JSONResponse($action());
        } catch (OCSNotFoundException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (OCSForbiddenException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (OCSBadRequestException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            $this->logger->error('ExportJobController::'.$label.' failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => $this->l10n->t('An unexpected error occurred')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end requireExportAdmin()
}//end class
