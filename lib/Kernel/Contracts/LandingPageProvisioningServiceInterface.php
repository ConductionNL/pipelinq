<?php

/**
 * Contract of LandingPageProvisioningService
 *
 * The public surface of LandingPageProvisioningService that other components call, so they depend
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
 * Contract of LandingPageProvisioningService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LandingPageProvisioningServiceInterface {

	/**
	 * Create a landing page for one campaign.
	 *
	 * @param string $campaignId The campaign.
	 * @param string $portal The portal slug; empty uses the configured one.
	 * @param string $route The in-portal route; empty derives one from the campaign value.
	 *
	 * @return array{error: string, portal: string, route: string, pageId: string, publicUrl: string, formId: string}
	 *         `error` is empty on success, otherwise Portaliq's own code or `portaliq_missing`.
	 *
	 * @spec openspec/specs/marketing-campaigns/spec.md#requirement-a-campaign-creates-its-landing-page-in-portaliq
	 */
	public function createFor(string $campaignId, string $portal = '', string $route = ''): array;
}//end interface
