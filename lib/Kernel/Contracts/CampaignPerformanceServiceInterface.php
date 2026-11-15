<?php

/**
 * Contract of CampaignPerformanceService
 *
 * The public surface of CampaignPerformanceService that other components call, so they depend
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
 * Contract of CampaignPerformanceService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CampaignPerformanceServiceInterface {

	/**
	 * The performance of one blast over a window.
	 *
	 * @param string      $blastId Blast UUID or slug.
	 * @param string|null $from    Window start `YYYY-MM-DD`; defaults to the blast's send date.
	 * @param string|null $to      Window end `YYYY-MM-DD`, inclusive; defaults to today.
	 *
	 * @return array<string, mixed>|null The performance record, or null when the blast does not exist.
	 *
	 * @spec openspec/specs/marketing-campaign-attribution/spec.md#requirement-campaign-performance-joins-site-sessions-to-a-blast
	 */
	public function forBlast(string $blastId, ?string $from=null, ?string $to=null): ?array;
}//end interface
