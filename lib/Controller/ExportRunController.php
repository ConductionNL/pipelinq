<?php

/**
 * Pipelinq ExportRunController.
 *
 * Read + retry API for the immutable export-run audit trail: filtered run list,
 * full run detail (manifest + schema snapshots) and a retry action that
 * re-enqueues a fresh pending run from a failed run's job. Every endpoint is
 * gated to the export-analyst group or a Nextcloud admin via ExportAccessPolicy
 * (ADR-005); reads/writes are scoped to this app's own register/schema, so a run
 * id belonging to another app resolves to a 404 (IDOR-safe). Generic run-object
 * CRUD is otherwise handled by OpenRegister's object API — these endpoints add
 * the filtering, snapshot join and retry the generic API cannot express.
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
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-010
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-011
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Export\ExportAccessPolicy;
use OCA\Pipelinq\Service\Export\ExportJobService;
use OCA\Pipelinq\Service\Export\ExportRunService;
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
 * Controller for export-run history + retry endpoints.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Thin read/retry surface
 *  delegating to the run + job services behind one error-handling helper.
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-011
 */
class ExportRunController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ExportRunService $runs The run service.
	 * @param ExportJobService $jobs The job service (retry).
	 * @param ExportAccessPolicy $policy The export access policy.
	 * @param IUserSession $userSession The user session.
	 * @param IL10N $l10n The localization service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		IRequest $request,
		private ExportRunService $runs,
		private ExportJobService $jobs,
		private ExportAccessPolicy $policy,
		private IUserSession $userSession,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * List export runs, filtered and newest-first.
	 *
	 * Accepts query params job_id, status, date_from, date_to.
	 *
	 * @return JSONResponse The matching runs.
	 *
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-011-01
	 */
	#[NoAdminRequired]
	public function listRuns(): JSONResponse {
		$filters = [
			'job_id' => (string)$this->request->getParam('job_id', ''),
			'status' => (string)$this->request->getParam('status', ''),
			'date_from' => (string)$this->request->getParam('date_from', ''),
			'date_to' => (string)$this->request->getParam('date_to', ''),
		];

		return $this->requireExportAdmin(
			action: fn (): array => ['runs' => $this->runs->listRuns(filters: $filters)],
			label: 'listRuns'
		);
	}//end listRuns()

	/**
	 * Show one export run with its file manifest and schema snapshots.
	 *
	 * @param string $id The run UUID.
	 *
	 * @return JSONResponse The run detail.
	 *
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-011-02
	 */
	#[NoAdminRequired]
	public function showRun(string $id): JSONResponse {
		return $this->requireExportAdmin(
			action: function () use ($id): array {
				$run = $this->runs->getRun(runId: $id);
				return [
					'run' => $run,
					'snapshots' => $this->runs->listSnapshots(runId: $id),
				];
			},
			label: 'showRun'
		);
	}//end showRun()

	/**
	 * Retry a failed (or partial) run by enqueuing a fresh pending run.
	 *
	 * Re-reads the originating job so the new run uses the current job config,
	 * and resolves the incremental watermark from the last succeeded run so a
	 * retry of an incremental export does not re-export already-shipped rows.
	 *
	 * @param string $id The run UUID to retry.
	 *
	 * @return JSONResponse The newly created pending run.
	 *
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-011-03
	 */
	#[NoAdminRequired]
	public function retryRun(string $id): JSONResponse {
		return $this->requireExportAdmin(
			action: function () use ($id): array {
				$run = $this->runs->getRun(runId: $id);
				$status = (string)($run['status'] ?? '');
				if (in_array($status, ['failed', 'partial', 'skipped_overlap'], true) === false) {
					throw new OCSBadRequestException(
						'Only failed, partial or skipped runs can be retried.'
					);
				}

				$job = $this->jobs->getJob(id: (string)($run['jobId'] ?? ''));

				$watermarkFrom = null;
				if ((string)($job['mode'] ?? 'full') === 'incremental') {
					$previous = $this->runs->lastSucceededRun(jobId: (string)($job['id'] ?? $job['uuid'] ?? ''));
					$watermarkRaw = ($previous['watermarkTo'] ?? null);
					if ($watermarkRaw !== null) {
						$watermarkFrom = (string)$watermarkRaw;
					}
				}

				return [
					'run' => $this->runs->createPendingRun(
						job: $job,
						watermarkFrom: $watermarkFrom
					),
				];
			},
			label: 'retryRun'
		);
	}//end retryRun()

	/**
	 * The acting user UID (empty when no session).
	 *
	 * @return string The UID.
	 */
	private function actingUser(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getUID();
	}//end actingUser()

	/**
	 * Run an action behind the export-admin gate with shared error handling.
	 *
	 * Returns 401 unauthenticated, 403 when not an export admin/analyst, 404 /
	 * 422 from the service's OCS exceptions, 500 otherwise (generic message).
	 *
	 * @param callable $action The action.
	 * @param string $label Log label.
	 *
	 * @return JSONResponse The response.
	 */
	private function requireExportAdmin(callable $action, string $label): JSONResponse {
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
			$this->logger->error('ExportRunController::' . $label . ' failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(
				['error' => $this->l10n->t('An unexpected error occurred')],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end requireExportAdmin()
}//end class
