<?php

/**
 * Contract of CampaignReportService
 *
 * The public surface of CampaignReportService that other components call, so they depend
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
 * Contract of CampaignReportService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CampaignReportServiceInterface {

	/**
	 * The report for one campaign.
	 *
	 * @param string $campaignId The campaign.
	 * @param string|null $from Window start `YYYY-MM-DD`; defaults to the campaign's own start.
	 * @param string|null $to Window end `YYYY-MM-DD`; defaults to the campaign's end, or today.
	 *
	 * @return array<string, mixed>|null The report, or null when the campaign does not exist.
	 *
	 * @spec openspec/specs/marketing-campaigns/spec.md#requirement-one-campaign-report-page
	 */
	public function forCampaign(string $campaignId, ?string $from = null, ?string $to = null): ?array;
}//end interface
