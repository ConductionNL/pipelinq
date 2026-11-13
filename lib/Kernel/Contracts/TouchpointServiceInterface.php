<?php

/**
 * Contract of TouchpointService
 *
 * The public surface of TouchpointService that other components call, so they depend
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
 * Contract of TouchpointService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TouchpointServiceInterface {

	/**
	 * Append one touchpoint.
	 *
	 * @param array<string, mixed> $touchpoint The row: `campaignId`, `kind`,
	 *                                         `occurredAt` and whatever else is known.
	 *
	 * @return array<string, mixed>|null The saved row, or null when the write failed.
	 *
	 * @spec openspec/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
	 */
	public function append(array $touchpoint): ?array;

	/**
	 * The channel a medium belongs to.
	 *
	 * A medium is what a link carried; a channel is what a marketer reads
	 * in the report. `cpc` and `display` are both paid, `social` is a
	 * post, and anything unknown is reported as it arrived rather than
	 * bucketed into "other", which would hide a typo in a UTM value.
	 *
	 * @param string $medium The `utm_medium` value.
	 *
	 * @return string The channel.
	 *
	 * @spec openspec/specs/marketing-campaigns/spec.md#requirement-one-campaign-report-page
	 */
	public function channelForMedium(string $medium): string;

	/**
	 * Whether a touchpoint already carries this nonce.
	 *
	 * @param string $nonce The producing system's own id for the interaction.
	 *
	 * @return bool True when the interaction was already recorded.
	 *
	 * @spec openspec/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
	 */
	public function existsForNonce(string $nonce): bool;
}//end interface
