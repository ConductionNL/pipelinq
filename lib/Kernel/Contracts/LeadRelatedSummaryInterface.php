<?php

/**
 * Contract of LeadRelatedSummary
 *
 * The public surface of LeadRelatedSummary that other components call, so they depend
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
 * Contract of LeadRelatedSummary.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LeadRelatedSummaryInterface {

	/**
	 * The summary of a lead: `earlier` ("2 earlier deals, both won"; '' when
	 * the client has none) and `hours` ("14.5"; '' when none are written).
	 *
	 * @param array<string, mixed> $lead The lead.
	 *
	 * @return array{earlier: string, hours: string} The summary.
	 *
	 * @spec openspec/specs/lead-management/spec.md#requirement-the-related-card-names-earlier-deals-and-hours
	 */
	public function forLead(array $lead): array;
}//end interface
