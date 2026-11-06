<?php

/**
 * Pipelinq ForecastController.
 *
 * REST API for forecast snapshot export (JSON/CSV, paginated, with calculation
 * audit) and manager override management. Permission-scoped per ADR-005.
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
 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-010, REQ-FRC-011
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ForecastAccess;
use OCA\Pipelinq\Service\ForecastExportService;
use OCA\Pipelinq\Service\ForecastOverrideDesk;
use OCA\Pipelinq\Service\ForecastSnapshotQuery;
use OCA\Pipelinq\Util\ReadsTypedRequestParameters;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * Forecast REST API controller.
 *
 * Thin: validates auth posture and delegates to the export and override
 * services. Every action requires an authenticated user; per-action scope is
 * enforced through {@see ForecastAccess} and denied requests are logged
 * and answered with 403 (no stack traces leak to the client).
 *
 * @spec exclude forecast has no owning requirement. pipeline-insights defers its config
 *   scenario to admin-settings, which never grew one
 */
class ForecastController extends Controller {
	use ReadsTypedRequestParameters;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ForecastAccess $access The acting user and their forecast scope.
	 * @param ForecastExportService $exportService The snapshot export service.
	 * @param ForecastOverrideDesk $overrides The override requests.
	 */
	public function __construct(
		IRequest $request,
		private ForecastAccess $access,
		private ForecastExportService $exportService,
		private ForecastOverrideDesk $overrides,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Export forecast snapshots (JSON or CSV) for a period and level.
	 *
	 * Reads periodId, level, ownerId (optional), format (json|csv), limit
	 * (max 200) and offset from the request. A parameter of the wrong type is
	 * a bare 400, as the dispatcher answers it for a typed action argument.
	 *
	 * @return Response The snapshot export.
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-010-01, REQ-FRC-010-02
	 */
	#[NoAdminRequired]
	public function snapshots(): Response {
		$values = $this->typedRequestParameters(spec: ForecastSnapshotQuery::PARAMETERS);
		if ($values === null) {
			return new Response(Http::STATUS_BAD_REQUEST);
		}

		$query = new ForecastSnapshotQuery(values: $values);
		$uid = $this->access->actingUserId();
		if ($uid === '') {
			return new JSONResponse(['error' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
		}

		if ($query->isComplete() === false) {
			return new JSONResponse(['error' => 'A valid period and level are required.'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->access->mayRead(uid: $uid, query: $query) === false) {
			return new JSONResponse(['error' => 'You may not read forecasts at this scope.'], Http::STATUS_FORBIDDEN);
		}

		return $this->exportResponse(query: $query);
	}//end snapshots()

	/**
	 * Create a forecast override.
	 *
	 * @return JSONResponse The created override or an error.
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-006-01, REQ-FRC-011-02
	 */
	#[NoAdminRequired]
	public function createOverride(): JSONResponse {
		$uid = $this->access->actingUserId();
		if ($uid === '') {
			return new JSONResponse(['error' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
		}

		$level = (string)$this->request->getParam('level', '');
		if ($this->access->mayOverride(uid: $uid, level: $level) === false) {
			return new JSONResponse(['error' => 'You may not override forecasts at this scope.'], Http::STATUS_FORBIDDEN);
		}

		$payload = $this->overrides->payloadOf(request: $this->request);
		$error = $this->overrides->invalidity(payload: $payload);
		if ($error !== null) {
			return new JSONResponse(['error' => $error], Http::STATUS_BAD_REQUEST);
		}

		$override = $this->overrides->create(payload: $payload, managerId: $uid);
		if ($override === null) {
			return new JSONResponse(['error' => 'Could not create the override.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($override, Http::STATUS_CREATED);
	}//end createOverride()

	/**
	 * Delete a forecast override (reverts the roll-up to the calculated amount).
	 *
	 * @param string $id The override UUID.
	 *
	 * @return JSONResponse The deletion result.
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-006-06
	 */
	#[NoAdminRequired]
	public function deleteOverride(string $id): JSONResponse {
		$uid = $this->access->actingUserId();
		if ($uid === '') {
			return new JSONResponse(['error' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
		}

		$existing = $this->overrides->stored(id: $id);
		if ($existing === null) {
			return new JSONResponse(['error' => 'Override not found.'], Http::STATUS_NOT_FOUND);
		}

		if ($this->access->mayDeleteOverride(uid: $uid, override: ['requestedId' => $id] + $existing) === false) {
			return new JSONResponse(['error' => 'You may not delete this override.'], Http::STATUS_FORBIDDEN);
		}

		if ($this->overrides->delete(id: $id) === false) {
			return new JSONResponse(['error' => 'Could not delete the override.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['deleted' => true]);
	}//end deleteOverride()

	/**
	 * The snapshot page a permitted query asks for, as JSON or a CSV download.
	 *
	 * @param ForecastSnapshotQuery $query The permitted query.
	 *
	 * @return Response
	 */
	private function exportResponse(ForecastSnapshotQuery $query): Response {
		$page = $this->exportService->exportSnapshots(
			periodId: $query->periodId(),
			level: $query->level(),
			ownerId: $query->ownerId(),
			limit: $query->limit(),
			offset: $query->offset()
		);

		if ($query->wantsCsv() === true) {
			return new DataDownloadResponse(
				data: $this->exportService->toCsv($page['snapshots']),
				filename: $query->csvFilename(),
				contentType: 'text/csv; charset=UTF-8'
			);
		}

		return new JSONResponse($page);
	}//end exportResponse()
}//end class
