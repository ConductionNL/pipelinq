<?php

/**
 * Pipelinq ContactReportController.
 *
 * The contact report's top subjects, occupancy and forecast.
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
 * @spec openspec/changes/kcc-report-top-occupancy-forecast-export/specs/contactmomenten-rapportage/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use DateTimeImmutable;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\ContactReportServiceInterface;
use OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck;
use OCA\Pipelinq\Service\ContactReport\ContactReportFilter;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The contact report routes beside /api/rapportage/kpis: each takes the
 * filters team, channel and employee, and each refuses a reader without
 * the CRM privilege before any contact moment is read, as the other
 * reporting routes do.
 *
 * @spec openspec/changes/kcc-report-top-occupancy-forecast-export/specs/contactmomenten-rapportage/spec.md
 */
class ContactReportController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest                      $request   The request.
	 * @param ContactReportServiceInterface $report    The contact report.
	 * @param CrmPrivilegeCheck             $privilege The session user and the CRM privilege check.
	 */
	public function __construct(
		IRequest $request,
		private readonly ContactReportServiceInterface $report,
		private readonly CrmPrivilegeCheck $privilege,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The ten subjects with the most contact moments in the period.
	 *
	 * @return JSONResponse The subjects, or the refusal.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/kcc-report-top-occupancy-forecast-export/specs/contactmomenten-rapportage/spec.md#requirement-the-report-lists-the-top-10-subjects
	 */
	public function topSubjects(): JSONResponse {
		$period = $this->permittedPeriod();
		if ($period instanceof JSONResponse) {
			return $period;
		}

		[$from, $to] = $period;
		return $this->reportOrFailure(report: fn (): array => $this->report->topSubjects(from: $from, to: $to, filter: $this->filter()));
	}//end topSubjects()

	/**
	 * The occupancy per employee and working hour in the period.
	 *
	 * @return JSONResponse The occupancy, or the refusal.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/kcc-report-top-occupancy-forecast-export/specs/contactmomenten-rapportage/spec.md#requirement-the-report-shows-occupancy-per-employee-and-hour
	 */
	public function occupancy(): JSONResponse {
		$period = $this->permittedPeriod();
		if ($period instanceof JSONResponse) {
			return $period;
		}

		[$from, $to] = $period;
		return $this->reportOrFailure(report: fn (): array => $this->report->occupancy(from: $from, to: $to, filter: $this->filter()));
	}//end occupancy()

	/**
	 * The expected contact moments for the next seven days.
	 *
	 * @return JSONResponse The forecast, or the refusal.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/kcc-report-top-occupancy-forecast-export/specs/contactmomenten-rapportage/spec.md#requirement-the-report-forecasts-the-coming-week
	 */
	public function forecast(): JSONResponse {
		$refusal = $this->privilege->refusalAsMessage();
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->reportOrFailure(report: fn (): array => $this->report->forecast(filter: $this->filter()));
	}//end forecast()

	/**
	 * The team, channel and employee filters of the request.
	 *
	 * @return ContactReportFilter The filter.
	 */
	private function filter(): ContactReportFilter {
		return new ContactReportFilter(
			team: $this->request->getParam('team', ''),
			channel: $this->request->getParam('channel', ''),
			employee: $this->request->getParam('employee', ''),
		);
	}//end filter()

	/**
	 * The requested period for a CRM user, or the refusal.
	 *
	 * @return array{0: string, 1: string}|JSONResponse The [from, to] period, or the refusal.
	 */
	private function permittedPeriod(): array|JSONResponse {
		$refusal = $this->privilege->refusalAsMessage();
		if ($refusal !== null) {
			return $refusal;
		}

		$from = $this->request->getParam('from', '');
		$to = $this->request->getParam('to', '');
		if (is_string($from) === false || is_string($to) === false || $from === '' || $to === '') {
			return new JSONResponse(['message' => 'Missing required parameters: from, to'], Http::STATUS_BAD_REQUEST);
		}

		if (self::isDate(value: $from) === false || self::isDate(value: $to) === false) {
			return new JSONResponse(['message' => 'Invalid date format. Use ISO 8601 (YYYY-MM-DD or full datetime)'], Http::STATUS_BAD_REQUEST);
		}

		return [$from, $to];
	}//end permittedPeriod()

	/**
	 * Whether a value is a parseable date or datetime.
	 *
	 * @param string $value The value.
	 *
	 * @return bool Whether it is.
	 */
	private static function isDate(string $value): bool {
		try {
			new DateTimeImmutable($value);
			return true;
		} catch (\Exception) {
			return false;
		}
	}//end isDate()

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
}//end class
