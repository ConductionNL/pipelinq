<?php

/**
 * Pipelinq LoyaltyReportingController.
 *
 * Read-only REST API for the loyalty reporting dashboard. Requires authenticated
 * user (admin-only by NC SecurityMiddleware default unless overridden).
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
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-008
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-009
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\LoyaltyReportingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Loyalty reporting REST controller.
 *
 * Endpoints require an authenticated user. To surface KPIs to programme managers
 * who are not Nextcloud admins, the methods bypass the admin requirement and
 * are gated by the userSession; constructor takes no action.
 *
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-008
 */
class LoyaltyReportingController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param LoyaltyReportingService $reportingService The reporting service.
	 * @param IUserSession $userSession The user session.
	 */
	public function __construct(
		IRequest $request,
		private LoyaltyReportingService $reportingService,
		private IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET KPIs for a programme.
	 *
	 * @param string $programmeId The programme UUID.
	 * @param ?string $from ISO-8601 date lower bound.
	 * @param ?string $to ISO-8601 date upper bound.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-008-01
	 */
	#[NoAdminRequired]
	public function kpis(string $programmeId, ?string $from = null, ?string $to = null): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(
			$this->reportingService->getKpis(programmeId: $programmeId, from: $from, to: $to)
		);
	}//end kpis()

	/**
	 * GET liability snapshot (IFRS 15 / RJ 270).
	 *
	 * @param string $programmeId The programme UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-009-01
	 */
	#[NoAdminRequired]
	public function liability(string $programmeId): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse($this->reportingService->getLiabilitySnapshot(programmeId: $programmeId));
	}//end liability()

	/**
	 * GET tier distribution.
	 *
	 * @param string $programmeId The programme UUID.
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function tierDistribution(string $programmeId): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(['tiers' => $this->reportingService->getTierReport(programmeId: $programmeId)]);
	}//end tierDistribution()

	/**
	 * GET expiry forecast.
	 *
	 * @param string $programmeId The programme UUID.
	 * @param int $days Window in days (default 30).
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function expiryForecast(string $programmeId, int $days = 30): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(
			$this->reportingService->getExpiryForecast(programmeId: $programmeId, days: $days)
		);
	}//end expiryForecast()
}//end class
