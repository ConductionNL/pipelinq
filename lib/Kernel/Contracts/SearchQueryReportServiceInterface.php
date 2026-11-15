<?php

/**
 * Contract of SearchQueryReportService
 *
 * The public surface of SearchQueryReportService that other components call, so they depend
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

/**
 * Contract of SearchQueryReportService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SearchQueryReportServiceInterface {

	/**
	 * The top queries by clicks over a window.
	 *
	 * @param string|null $from Window start `YYYY-MM-DD`; defaults to 28 days ago.
	 * @param string|null $to Window end `YYYY-MM-DD`, inclusive; defaults to today.
	 * @param int $limit Rows to return (1..500).
	 * @param string|null $property Restrict to one property; null for all.
	 *
	 * @return array{from: string, to: string, rows: array<int, array<string, mixed>>, totalQueries: int}
	 *
	 * @spec openspec/specs/marketing-campaign-attribution/spec.md#requirement-search-queries-page-lists-top-queries
	 */
	public function topQueries(?string $from = null, ?string $to = null, int $limit = 50, ?string $property = null): array;
}//end interface
