<?php

/**
 * Contract of MatomoReportService
 *
 * The public surface of MatomoReportService that other components call, so they depend
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
 * Contract of MatomoReportService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MatomoReportServiceInterface {

	/**
	 * The three reports for a window, or the reason there are none.
	 *
	 * @param string $from Window start `YYYY-MM-DD`.
	 * @param string $to Window end `YYYY-MM-DD`, inclusive.
	 *
	 * @return array<string, mixed> `{connected, reason, from, to, campaigns[], referrerTypes[], goals}`.
	 *
	 * @spec openspec/specs/marketing-analytics-connectors/spec.md#requirement-three-matomo-reports-matched-to-the-campaigns-we-already-mint
	 */
	public function report(string $from, string $to): array;
}//end interface
