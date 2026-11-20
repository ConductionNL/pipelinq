<?php

/**
 * Contract of ContactReportService
 *
 * The public surface of ContactReportService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

use OCA\Pipelinq\Service\ContactReport\ContactReportFilter;

/**
 * Contract of ContactReportService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/kcc-report-top-occupancy-forecast-export/specs/contactmomenten-rapportage/spec.md
 */
interface ContactReportServiceInterface {
	/**
	 * The subjects with the most contact moments in a period, most first.
	 *
	 * @param string              $from   The first day.
	 * @param string              $to     The last day.
	 * @param ContactReportFilter $filter The filter.
	 *
	 * @return array{subjects: array<int, array{subject: string, count: int}>} At most ten subjects.
	 *
	 * @spec openspec/changes/kcc-report-top-occupancy-forecast-export/specs/contactmomenten-rapportage/spec.md#requirement-the-report-lists-the-top-10-subjects
	 */
	public function topSubjects(string $from, string $to, ContactReportFilter $filter): array;

	/**
	 * Per employee and working hour, the share of that hour spent on contact moments.
	 *
	 * @param string              $from   The first day.
	 * @param string              $to     The last day.
	 * @param ContactReportFilter $filter The filter.
	 *
	 * @return array{hours: array<int, int>, employees: array<int, array{employee: string, values: array<int, float>}>} The occupancy.
	 *
	 * @spec openspec/changes/kcc-report-top-occupancy-forecast-export/specs/contactmomenten-rapportage/spec.md#requirement-the-report-shows-occupancy-per-employee-and-hour
	 */
	public function occupancy(string $from, string $to, ContactReportFilter $filter): array;

	/**
	 * The expected contact moments for each of the next seven days.
	 *
	 * @param ContactReportFilter $filter The filter.
	 *
	 * @return array<string, mixed> The forecast.
	 *
	 * @spec openspec/changes/kcc-report-top-occupancy-forecast-export/specs/contactmomenten-rapportage/spec.md#requirement-the-report-forecasts-the-coming-week
	 */
	public function forecast(ContactReportFilter $filter): array;

	/**
	 * The report as an Excel workbook.
	 *
	 * @param string              $from   The first day.
	 * @param string              $to     The last day.
	 * @param ContactReportFilter $filter The filter.
	 *
	 * @return string The .xlsx bytes.
	 *
	 * @spec openspec/changes/kcc-report-top-occupancy-forecast-export/specs/contactmomenten-rapportage/spec.md#requirement-the-report-exports-to-excel-and-pdf
	 */
	public function workbook(string $from, string $to, ContactReportFilter $filter): string;

	/**
	 * The report as a PDF document.
	 *
	 * @param string              $from   The first day.
	 * @param string              $to     The last day.
	 * @param ContactReportFilter $filter The filter.
	 *
	 * @return string The PDF bytes.
	 *
	 * @spec openspec/changes/kcc-report-top-occupancy-forecast-export/specs/contactmomenten-rapportage/spec.md#requirement-the-report-exports-to-excel-and-pdf
	 */
	public function document(string $from, string $to, ContactReportFilter $filter): string;
}//end interface
