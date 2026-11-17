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
 * @spec openspec/changes/archive/2026-06-14-contactmomenten-rapportage/tasks.md#task-1
 * @spec openspec/specs/contactmomenten-rapportage/spec.md#requirement-sla-configuration
 * @spec openspec/specs/contactmomenten-rapportage/spec.md#requirement-export-and-bi-integration
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
		// @spec openspec/changes/archive/2026-06-14-contactmomenten-rapportage/tasks.md#task-1
declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use DateInterval;
use DateTimeImmutable;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\ContactReportServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ReportingServiceInterface;
use OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck;
use OCA\Pipelinq\Service\ContactReport\ContactReportFilter;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Controller for reporting endpoints and SLA configuration.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Over the threshold since
 * each reporting endpoint gained its CRM authorization guard. These aggregate
 * across the whole service desk, so the guard is the point rather than an
 * inconvenience; the added complexity is one uniform early return per method.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The export route answers CSV,
 * Excel and PDF from one URL, so it also reaches the contact report and its
 * filter; splitting the route by format is not possible on one path.
 *
 * @spec openspec/changes/archive/2026-06-14-contactmomenten-rapportage/tasks.md#task-1
 */
class ReportingController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ReportingServiceInterface $reportingService The reporting service.
	 * @param CrmPrivilegeCheck $privilege The session user and the CRM privilege check.
	 * @param ContactReportServiceInterface $contactReport The contact report, for the Excel and PDF exports.
	 */
	public function __construct(
		IRequest $request,
		private ReportingServiceInterface $reportingService,
		private CrmPrivilegeCheck $privilege,
		private ContactReportServiceInterface $contactReport,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Get KPI data for the given date range.
	 *
	 * @return JSONResponse Total contacts, FCR %, avg handling time, SLA compliance.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-06-14-contactmomenten-rapportage/tasks.md#task-1
	 */
	public function getKpis(): JSONResponse {
		$period = $this->permittedPeriod();
		if ($period instanceof JSONResponse) {
			return $period;
		}

		[$from, $to] = $period;
		return $this->reportOrFailure(report: fn (): array => $this->reportingService->getKpis($from, $to));
	}//end getKpis()

	/**
	 * Get channel distribution and trend data.
	 *
	 * @return JSONResponse Channel distribution counts and time-series trend.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-06-14-contactmomenten-rapportage/tasks.md#task-1
	 */
	public function getChannels(): JSONResponse {
		$period = $this->permittedPeriod();
		if ($period instanceof JSONResponse) {
			return $period;
		}

		[$from, $to] = $period;
		$granularity = $this->request->getParam('granularity', 'daily');
		return $this->reportOrFailure(
			report: fn (): array => [
				'distribution' => $this->reportingService->getChannelDistribution($from, $to),
				'trend' => $this->reportingService->getChannelTrend($from, $to, $granularity),
			]
		);
	}//end getChannels()

	/**
	 * Get per-agent performance table.
	 *
	 * @return JSONResponse Per-agent metrics: count, FCR %, avg handling time.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-06-14-contactmomenten-rapportage/tasks.md#task-1
	 */
	public function getAgents(): JSONResponse {
		$period = $this->permittedPeriod();
		if ($period instanceof JSONResponse) {
			return $period;
		}

		[$from, $to] = $period;
		return $this->reportOrFailure(
			report: fn (): array => ['agents' => $this->reportingService->getAgentPerformance($from, $to)]
		);
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
		// Reporting aggregates across the customer base — a CRM capability.
		$refusal = $this->privilege->refusalAsMessage();
		if ($refusal !== null) {
			return $refusal;
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
	#[AuthorizedAdminSetting(AppIdentity::APP_ID)]
	public function updateSla(): JSONResponse {
		try {
			return $this->savedSlaTargets(targets: $this->request->getParam('targets', []));
		} catch (\Throwable) {
			return new JSONResponse(['message' => 'Operation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end updateSla()

	/**
	 * Store the posted SLA targets and answer the stored set, or 400 when any is refused.
	 *
	 * @param mixed $targets The posted targets, channel => metric => value.
	 *
	 * @return JSONResponse The stored targets, or the refusal.
	 */
	private function savedSlaTargets(mixed $targets): JSONResponse {
		if (is_array($targets) === false) {
			return new JSONResponse(['message' => 'Operation failed'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->rejectedSlaTargets(targets: $targets) > 0) {
			return new JSONResponse(['message' => 'Operation failed'], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(
			[
				'success' => true,
				'targets' => $this->reportingService->getAllSlaTargets(),
			]
		);
	}//end savedSlaTargets()

	/**
	 * Store every channel's targets; a channel that is not a map is skipped.
	 *
	 * @param array<mixed, mixed> $targets The posted targets, channel => metric => value.
	 *
	 * @return int How many targets the service refused.
	 */
	private function rejectedSlaTargets(array $targets): int {
		$skipped = 0;
		foreach ($targets as $channel => $metrics) {
			if (is_array($metrics) === true) {
				$skipped += $this->rejectedChannelTargets(channel: $channel, metrics: $metrics);
			}
		}

		return $skipped;
	}//end rejectedSlaTargets()

	/**
	 * Store one channel's targets.
	 *
	 * @param int|string          $channel The channel.
	 * @param array<mixed, mixed> $metrics Metric => value.
	 *
	 * @return int How many of them the service refused.
	 */
	private function rejectedChannelTargets(int|string $channel, array $metrics): int {
		$skipped = 0;
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

		return $skipped;
	}//end rejectedChannelTargets()

	/**
	 * Export the reporting dashboard's figures as CSV.
	 *
	 * The same figures the dashboard shows for the period (the KPIs, the
	 * contacts per channel and the per-agent metrics), one figure per row
	 * under the header Section;Item;Metric;Value, semicolon-separated and
	 * UTF-8 with a BOM so Excel opens it with its characters intact.
	 *
	 * @return DataDownloadResponse|JSONResponse The CSV download or error.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/contactmomenten-rapportage/spec.md#requirement-export-and-bi-integration
	 * With format=xlsx or format=pdf the same route answers the contact
	 * report as a workbook or a document instead, filtered on team, channel
	 * and employee; any other format is refused with a 400 before anything
	 * is generated.
	 *
	 * @spec openspec/changes/wire-rapportage-csv-export/tasks.md#2-build-the-export-table
	 * @spec openspec/changes/kcc-report-top-occupancy-forecast-export/specs/contactmomenten-rapportage/spec.md#requirement-the-report-exports-to-excel-and-pdf
	 */
	public function exportCsv(): DataDownloadResponse|JSONResponse {
		$period = $this->permittedPeriod();
		if ($period instanceof JSONResponse) {
			return $period;
		}

		[$from, $to] = $period;
		$format = $this->request->getParam('format', 'csv');
		if ($format !== 'csv' && $format !== null && $format !== '') {
			return $this->documentExport(format: $format, from: $from, to: $to);
		}

		try {
			$csv = $this->reportingService->generateCsv(
				headers: ['Section', 'Item', 'Metric', 'Value'],
				rows: $this->exportRows(from: $from, to: $to)
			);
		} catch (\Throwable) {
			return new JSONResponse(['message' => 'Operation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new DataDownloadResponse(
			data: $csv,
			filename: sprintf('rapportage-%s-%s.csv', $this->dayOf(date: $from), $this->dayOf(date: $to)),
			contentType: 'text/csv; charset=UTF-8'
		);
	}//end exportCsv()

	/**
	 * The contact report as a workbook or a document, or the 400 for an unknown format.
	 *
	 * @param mixed  $format The requested format.
	 * @param string $from   The period's start.
	 * @param string $to     The period's end.
	 *
	 * @return DataDownloadResponse|JSONResponse The download, or the refusal or failure.
	 *
	 * @spec openspec/changes/kcc-report-top-occupancy-forecast-export/specs/contactmomenten-rapportage/spec.md#requirement-the-report-exports-to-excel-and-pdf
	 */
	private function documentExport(mixed $format, string $from, string $to): DataDownloadResponse|JSONResponse {
		$types = [
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'pdf' => 'application/pdf',
		];
		if (is_string($format) === false || isset($types[$format]) === false) {
			return new JSONResponse(['message' => 'Unsupported format. Use csv, xlsx or pdf'], Http::STATUS_BAD_REQUEST);
		}

		$filter = new ContactReportFilter(
			team: $this->request->getParam('team', ''),
			channel: $this->request->getParam('channel', ''),
			employee: $this->request->getParam('employee', ''),
		);
		try {
			$data = match ($format) {
				'xlsx' => $this->contactReport->workbook(from: $from, to: $to, filter: $filter),
				default => $this->contactReport->document(from: $from, to: $to, filter: $filter),
			};
		} catch (\Throwable) {
			return new JSONResponse(['message' => 'Operation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new DataDownloadResponse(
			data: $data,
			filename: sprintf('rapportage-%s-%s.%s', $this->dayOf(date: $from), $this->dayOf(date: $to), $format),
			contentType: $types[$format]
		);
	}//end documentExport()

	/**
	 * The export's rows: the KPIs, then the contacts per channel, then each agent's metrics.
	 *
	 * @param string $from The period's start.
	 * @param string $to   The period's end.
	 *
	 * @return array<int, array<int, string>> The rows: section, item, metric, value.
	 */
	private function exportRows(string $from, string $to): array {
		$rows = [];
		foreach ($this->reportingService->getKpis($from, $to) as $metric => $value) {
			$rows[] = ['kpi', '', (string)$metric, (string)$value];
		}

		foreach ($this->reportingService->getChannelDistribution($from, $to) as $channel => $count) {
			$rows[] = ['channel', (string)$channel, 'count', (string)$count];
		}

		foreach ($this->reportingService->getAgentPerformance($from, $to) as $agent => $metrics) {
			foreach ($metrics as $metric => $value) {
				$rows[] = ['agent', (string)$agent, (string)$metric, (string)$value];
			}
		}

		return $rows;
	}//end exportRows()

	/**
	 * The calendar day of a validated date, for the file name.
	 *
	 * @param string $date The date or datetime.
	 *
	 * @return string The day as YYYY-MM-DD.
	 */
	private function dayOf(string $date): string {
		return (new DateTimeImmutable($date))->format('Y-m-d');
	}//end dayOf()

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

		return $this->namedPeriodRange(period: (string)$this->request->getParam('period', ''));
	}//end resolvePeriodRange()

	/**
	 * The [from, to] range a named period (today / week / month) stands for, ending today.
	 *
	 * @param string $period The period token.
	 *
	 * @return array{0: string, 1: string} The range, or ['', ''] for no or an unknown token.
	 */
	private function namedPeriodRange(string $period): array {
		if (in_array($period, ['today', 'week', 'month'], true) === false) {
			return ['', ''];
		}

		$now = new DateTimeImmutable('now');
		$fmt = 'Y-m-d';
		return match ($period) {
			'today' => [$now->format($fmt), $now->format($fmt)],
			'week' => [$this->mondayOf(day: $now)->format($fmt), $now->format($fmt)],
			default => [$now->format('Y-m-01'), $now->format($fmt)],
		};
	}//end namedPeriodRange()

	/**
	 * The Monday of the week a day falls in.
	 *
	 * @param DateTimeImmutable $day The day.
	 *
	 * @return DateTimeImmutable The Monday.
	 */
	private function mondayOf(DateTimeImmutable $day): DateTimeImmutable {
		$dayNum = (int)$day->format('N');
		return $day->sub(new DateInterval('P' . ($dayNum - 1) . 'D'));
	}//end mondayOf()

	/**
	 * The requested period for a CRM user, or the refusal: reporting
	 * aggregates across the customer base, a CRM capability.
	 *
	 * @return array{0: string, 1: string}|JSONResponse The period, or the refusal.
	 */
	private function permittedPeriod(): array|JSONResponse {
		$refusal = $this->privilege->refusalAsMessage();
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->validatedPeriod();
	}//end permittedPeriod()

	/**
	 * The requested [from, to] period, or the 400 that refuses it.
	 *
	 * @return array{0: string, 1: string}|JSONResponse The period, or the refusal.
	 */
	private function validatedPeriod(): array|JSONResponse {
		[$from, $to] = $this->resolvePeriodRange();
		if ($from === '' || $to === '') {
			return new JSONResponse(['message' => 'Missing required parameters: from, to (or period)'], Http::STATUS_BAD_REQUEST);
		}

		// Validate date format.
		if ($this->isValidDate(date: $from) === false || $this->isValidDate(date: $to) === false) {
			return new JSONResponse(['message' => 'Invalid date format. Use ISO 8601 (YYYY-MM-DD or full datetime)'], Http::STATUS_BAD_REQUEST);
		}

		return [$from, $to];
	}//end validatedPeriod()

	/**
	 * Answer a report, or the generic 500 when building it fails.
	 *
	 * @param callable(): array<string, mixed> $report Builds the report.
	 *
	 * @return JSONResponse The report, or the failure.
	 */
	private function reportOrFailure(callable $report): JSONResponse {
		try {
			return new JSONResponse($report());
		} catch (\Throwable) {
			return new JSONResponse(['message' => 'Operation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end reportOrFailure()

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
