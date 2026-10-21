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
 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-010, REQ-FRC-011
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ForecastAccessPolicy;
use OCA\Pipelinq\Service\ForecastExportService;
use OCA\Pipelinq\Service\ForecastOverrideService;
use OCA\Pipelinq\Service\ForecastSummaryService;
use DateTimeImmutable;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Forecast REST API controller.
 *
 * Thin: validates auth posture and delegates to the export and override
 * services. Every action requires an authenticated user; per-action scope is
 * enforced through {@see ForecastAccessPolicy} and denied requests are logged
 * and answered with 403 (no stack traces leak to the client).
 *
 * @spec exclude forecast has no owning requirement. pipeline-insights defers its config
 *   scenario to admin-settings, which never grew one
 */
class ForecastController extends Controller {
	/**
	 * The allowed hierarchy levels for a snapshot query.
	 *
	 * @var array<int, string>
	 */
	private const READ_LEVELS = ['rep', 'team', 'division', 'company'];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param IUserSession $userSession The user session.
	 * @param ForecastAccessPolicy $accessPolicy The forecast access policy.
	 * @param ForecastExportService $exportService The snapshot export service.
	 * @param ForecastOverrideService $overrideService The override management service.
	 * @param LoggerInterface $logger The logger.
	 * @param ForecastSummaryService|null $summaryService The forecast page's figures.
	 */
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private ForecastAccessPolicy $accessPolicy,
		private ForecastExportService $exportService,
		private ForecastOverrideService $overrideService,
		private LoggerInterface $logger,
		private ?ForecastSummaryService $summaryService = null,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The forecast page's figures for a level of the current quarter: the
	 * period, the totals, one row per owner one level down, and the trend.
	 * With `format=csv` the latest snapshot per owner downloads as CSV.
	 *
	 * @param string $level   The level the page shows: rep, team, division or company.
	 * @param string $format  `json` or `csv`.
	 * @param string $ownerId One seller's own figures, at the rep level.
	 *
	 * @return Response The figures, or the CSV.
	 *
	 * @spec exclude forecast has no owning requirement. pipeline-insights defers its config
	 *   scenario to admin-settings, which never grew one
	 */
	#[NoAdminRequired]
	public function summary(string $level = 'team', string $format = 'json', string $ownerId = ''): Response {
		$uid = $this->currentUserId();
		if ($uid === '') {
			return new JSONResponse(['error' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
		}

		if (in_array($level, self::READ_LEVELS, true) === false || $this->summaryService === null) {
			return new JSONResponse(['error' => 'A valid level is required.'], Http::STATUS_BAD_REQUEST);
		}

		$childLevel = ForecastSummaryService::CHILD_LEVEL[$level];
		$owner = null;
		if (trim($ownerId) !== '') {
			$childLevel = 'rep';
			$owner = trim($ownerId);
		}

		if ($this->accessPolicy->canRead(userId: $uid, level: $childLevel, ownerId: $owner) === false) {
			$this->logger->warning('Pipelinq: forecast summary denied', ['uid' => $uid, 'level' => $level]);
			return new JSONResponse(['error' => 'You may not read forecasts at this scope.'], Http::STATUS_FORBIDDEN);
		}

		$now = new DateTimeImmutable();
		if (strtolower($format) === 'csv') {
			$period = $this->summaryService->currentPeriod(now: $now);
			$latest = [];
			foreach ($this->summaryService->snapshots(periodId: $period['periodId'], level: $childLevel, ownerId: $owner) as $row) {
				$latest[(string)($row['owner_id'] ?? '')] = $row;
			}

			return new DataDownloadResponse(
				data: $this->exportService->toCsv(array_values($latest)),
				filename: sprintf('forecast-%s-%s.csv', $period['periodId'], $childLevel),
				contentType: 'text/csv; charset=UTF-8'
			);
		}

		return new JSONResponse($this->summaryService->summary(level: $level, now: $now, ownerId: $owner));
	}//end summary()

	/**
	 * Export forecast snapshots (JSON or CSV) for a period and level.
	 *
	 * @param string $periodId The fiscal period id.
	 * @param string $level The hierarchy level.
	 * @param string|null $ownerId Optional owner filter.
	 * @param string $format The export format (json|csv).
	 * @param int $limit The page size (max 200).
	 * @param int $offset The page offset.
	 *
	 * @return Response The snapshot export.
	 *
	 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-010-01, REQ-FRC-010-02
	 */
	#[NoAdminRequired]
	public function snapshots(
		string $periodId = '',
		string $level = '',
		?string $ownerId = null,
		string $format = 'json',
		int $limit = 50,
		int $offset = 0,
	): Response {
		$uid = $this->currentUserId();
		if ($uid === '') {
			return new JSONResponse(['error' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
		}

		if ($periodId === '' || in_array($level, self::READ_LEVELS, true) === false) {
			return new JSONResponse(['error' => 'A valid period and level are required.'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->accessPolicy->canRead(userId: $uid, level: $level, ownerId: $ownerId) === false) {
			$this->logger->warning('Pipelinq: forecast read denied', ['uid' => $uid, 'level' => $level, 'ownerId' => $ownerId]);
			return new JSONResponse(['error' => 'You may not read forecasts at this scope.'], Http::STATUS_FORBIDDEN);
		}

		$limit = max(1, min($limit, 200));
		$offset = max(0, $offset);

		$page = $this->exportService->exportSnapshots(
			periodId: $periodId,
			level: $level,
			ownerId: $ownerId,
			limit: $limit,
			offset: $offset
		);

		if (strtolower($format) === 'csv') {
			$csv = $this->exportService->toCsv($page['snapshots']);
			return new DataDownloadResponse(
				data: $csv,
				filename: sprintf('forecast-%s-%s.csv', $periodId, $level),
				contentType: 'text/csv; charset=UTF-8'
			);
		}

		return new JSONResponse($page);
	}//end snapshots()

	/**
	 * Create a forecast override.
	 *
	 * @return JSONResponse The created override or an error.
	 *
	 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-006-01, REQ-FRC-011-02
	 */
	#[NoAdminRequired]
	public function createOverride(): JSONResponse {
		$uid = $this->currentUserId();
		if ($uid === '') {
			return new JSONResponse(['error' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
		}

		$level = (string)$this->request->getParam('level', '');
		if ($this->accessPolicy->canOverride(userId: $uid, level: $level) === false) {
			$this->logger->warning('Pipelinq: forecast override denied', ['uid' => $uid, 'level' => $level]);
			return new JSONResponse(['error' => 'You may not override forecasts at this scope.'], Http::STATUS_FORBIDDEN);
		}

		$payload = [
			'period_id' => (string)$this->request->getParam('period_id', ''),
			'override_owner_id' => (string)$this->request->getParam('override_owner_id', ''),
			'level' => $level,
			'category' => (string)$this->request->getParam('category', 'commit'),
			'override_amount' => $this->request->getParam('override_amount'),
			'reason' => (string)$this->request->getParam('reason', ''),
		];

		$error = $this->overrideService->validatePayload($payload);
		if ($error !== null) {
			return new JSONResponse(['error' => $error], Http::STATUS_BAD_REQUEST);
		}

		try {
			$override = $this->overrideService->createOverride(payload: $payload, managerId: $uid);
		} catch (\Throwable $e) {
			$this->logger->error('Pipelinq: failed to create forecast override', ['exception' => $e->getMessage()]);
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
	 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-006-06
	 */
	#[NoAdminRequired]
	public function deleteOverride(string $id): JSONResponse {
		$uid = $this->currentUserId();
		if ($uid === '') {
			return new JSONResponse(['error' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
		}

		$existing = $this->overrideService->getOverride($id);
		if ($existing === null) {
			return new JSONResponse(['error' => 'Override not found.'], Http::STATUS_NOT_FOUND);
		}

		$level = (string)($existing['level'] ?? '');
		if ($this->accessPolicy->canOverride(userId: $uid, level: $level) === false) {
			$this->logger->warning('Pipelinq: forecast override delete denied', ['uid' => $uid, 'id' => $id]);
			return new JSONResponse(['error' => 'You may not delete this override.'], Http::STATUS_FORBIDDEN);
		}

		$deleted = $this->overrideService->deleteOverride($id);
		if ($deleted === false) {
			return new JSONResponse(['error' => 'Could not delete the override.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['deleted' => true]);
	}//end deleteOverride()

	/**
	 * Resolve the current authenticated user id.
	 *
	 * @return string The user UID, or empty string when unauthenticated.
	 */
	private function currentUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getUID();
	}//end currentUserId()
}//end class
