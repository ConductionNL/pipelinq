<?php

/**
 * Pipelinq ReportingController.
 *
 * Controller for contact moment reporting and SLA configuration.
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
 * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-1
 * @spec openspec/specs/contactmomenten-rapportage/spec.md#requirement-sla-configuration
 * @spec openspec/specs/contactmomenten-rapportage/spec.md#requirement-export-and-bi-integration
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
// @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-1
declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use DateTimeImmutable;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\ReportingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for reporting endpoints and SLA configuration.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Over the threshold since
 * each reporting endpoint gained its CRM authorization guard. These aggregate
 * across the whole service desk, so the guard is the point rather than an
 * inconvenience; the added complexity is one uniform early return per method.
 *
 * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-1
 */
class ReportingController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ReportingService $reportingService The reporting service.
	 * @param IUserSession $userSession The user session.
	 * @param ObjectOwnerAccessPolicy $accessPolicy Per-object owner access policy.
	 */
	public function __construct(
		IRequest $request,
		private ReportingService $reportingService,
		private IUserSession $userSession,
		private ObjectOwnerAccessPolicy $accessPolicy,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Get KPI data for the given date range.
	 *
	 * @return JSONResponse Total contacts, FCR %, avg handling time, SLA compliance.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-1
	 */
	public function getKpis(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		// Reporting aggregates across the customer base — a CRM capability.
		if ($this->accessPolicy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		[$from, $to] = $this->resolvePeriodRange();

		if ($from === '' || $to === '') {
			return new JSONResponse(['message' => 'Missing required parameters: from, to (or period)'], Http::STATUS_BAD_REQUEST);
		}

		// Validate date format.
		if ($this->isValidDate(date: $from) === false || $this->isValidDate(date: $to) === false) {
			return new JSONResponse(['message' => 'Invalid date format. Use ISO 8601 (YYYY-MM-DD or full datetime)'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$kpis = $this->reportingService->getKpis($from, $to);
			return new JSONResponse($kpis);
		} catch (\Throwable) {
			return new JSONResponse(['message' => 'Operation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try
	}//end getKpis()

	/**
	 * Get channel distribution and trend data.
	 *
	 * @return JSONResponse Channel distribution counts and time-series trend.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-1
	 */
	public function getChannels(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		// Reporting aggregates across the customer base — a CRM capability.
		if ($this->accessPolicy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		[$from, $to] = $this->resolvePeriodRange();
		$granularity = $this->request->getParam('granularity', 'daily');

		if ($from === '' || $to === '') {
			return new JSONResponse(['message' => 'Missing required parameters: from, to (or period)'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->isValidDate(date: $from) === false || $this->isValidDate(date: $to) === false) {
			return new JSONResponse(['message' => 'Invalid date format. Use ISO 8601 (YYYY-MM-DD or full datetime)'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$distribution = $this->reportingService->getChannelDistribution($from, $to);
			$trend = $this->reportingService->getChannelTrend($from, $to, $granularity);

			return new JSONResponse(
				[
					'distribution' => $distribution,
					'trend' => $trend,
				]
			);
		} catch (\Throwable) {
			return new JSONResponse(['message' => 'Operation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try
	}//end getChannels()

	/**
	 * Get per-agent performance table.
	 *
	 * @return JSONResponse Per-agent metrics: count, FCR %, avg handling time.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-1
	 */
	public function getAgents(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		// Reporting aggregates across the customer base — a CRM capability.
		if ($this->accessPolicy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		[$from, $to] = $this->resolvePeriodRange();

		if ($from === '' || $to === '') {
			return new JSONResponse(['message' => 'Missing required parameters: from, to (or period)'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->isValidDate(date: $from) === false || $this->isValidDate(date: $to) === false) {
			return new JSONResponse(['message' => 'Invalid date format. Use ISO 8601 (YYYY-MM-DD or full datetime)'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$agents = $this->reportingService->getAgentPerformance($from, $to);
			return new JSONResponse(['agents' => $agents]);
		} catch (\Throwable) {
			return new JSONResponse(['message' => 'Operation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try
	}//end getAgents()

	/**
	 * Get SLA configuration.
	 *
	 * @return JSONResponse The SLA targets.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/contactmomenten-rapportage/spec.md#requirement-sla-configuration
	 */
	public function getSla(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		// Reporting aggregates across the customer base — a CRM capability.
		if ($this->accessPolicy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		try {
			$targets = $this->reportingService->getAllSlaTargets();
			return new JSONResponse(['targets' => $targets]);
		} catch (\Throwable) {
			return new JSONResponse(['message' => 'Operation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try
	}//end getSla()

	/**
	 * Update SLA configuration.
	 *
	 * Admin-only endpoint (requires admin settings permission).
	 *
	 * @return JSONResponse The updated SLA targets.
	 *
	 * @spec openspec/specs/contactmomenten-rapportage/spec.md#requirement-sla-configuration
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function updateSla(): JSONResponse {
		try {
			$targets = $this->request->getParam('targets', []);

			if (is_array($targets) === false) {
				return new JSONResponse(['message' => 'Operation failed'], Http::STATUS_BAD_REQUEST);
			}

			$skipped = 0;
			foreach ($targets as $channel => $metrics) {
				if (is_array($metrics) === false) {
					continue;
				}

				foreach ($metrics as $metric => $value) {
					$accepted = $this->reportingService->setSlaTarget(
						channel: $channel,
						metric: $metric,
						value: (string)$value,
					);
					if ($accepted === false) {
						$skipped++;
					}
				}
			}

			if ($skipped > 0) {
				return new JSONResponse(['message' => 'Operation failed'], Http::STATUS_BAD_REQUEST);
			}

			return new JSONResponse(
				[
					'success' => true,
					'targets' => $this->reportingService->getAllSlaTargets(),
				]
			);
		} catch (\Throwable) {
			return new JSONResponse(['message' => 'Operation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try
	}//end updateSla()

	/**
	 * Export reporting data as CSV.
	 *
	 * @return DataDownloadResponse|JSONResponse The CSV download or error.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/contactmomenten-rapportage/spec.md#requirement-export-and-bi-integration
	 */
	public function exportCsv(): DataDownloadResponse|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		// Reporting aggregates across the customer base — a CRM capability.
		if ($this->accessPolicy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		// CSV export requires OpenRegister data integration.
		// Returning 501 until OR contactmoment retrieval is wired.
		return new JSONResponse(
			['message' => 'Export not yet implemented'],
			Http::STATUS_NOT_IMPLEMENTED,
		);
	}//end exportCsv()

	/**
	 * Resolve an explicit (from, to) pair, defaulting from a relative `period`
	 * token (today / week / month) when both bounds are empty.
	 *
	 * Mirrors the legacy dashboard's client-side semantics so a declarative
	 * type:dashboard can drive the range with a single static `period`
	 * pageFilter instead of computing dates in JS: today = [today, today],
	 * week = [Monday-of-this-week, today], month = [1st-of-month, today]. An
	 * explicit from/to (e.g. the date-range pills) always wins.
	 *
	 * @return array{0: string, 1: string} The [from, to] YYYY-MM-DD pair.
	 */
	private function resolvePeriodRange(): array {
		$from = (string)$this->request->getParam('from', '');
		$to = (string)$this->request->getParam('to', '');
		if ($from !== '' || $to !== '') {
			return [$from, $to];
		}

		$period = (string)$this->request->getParam('period', '');
		if ($period === '') {
			return ['', ''];
		}

		$now = new DateTimeImmutable('now');
		$fmt = 'Y-m-d';
		switch ($period) {
			case 'today':
				$today = $now->format($fmt);
				return [$today, $today];
			case 'week':
				$dayNum = (int)$now->format('N');
				$monday = $now->modify('-' . ($dayNum - 1) . ' days');
				return [$monday->format($fmt), $now->format($fmt)];
			case 'month':
				return [$now->format('Y-m-01'), $now->format($fmt)];
			default:
				return ['', ''];
		}//end switch
	}//end resolvePeriodRange()

	/**
	 * Validate that a string is a parseable date or datetime.
	 *
	 * @param string $date The date string to validate.
	 *
	 * @return bool True if parseable, false otherwise.
	 */
	private function isValidDate(string $date): bool {
		if ($date === '') {
			return false;
		}

		try {
			new DateTimeImmutable($date);
			return true;
		} catch (\Exception) {
			return false;
		}//end try
	}//end isValidDate()
}//end class
