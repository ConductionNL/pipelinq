<?php

/**
 * Contract of SocialMetricsService
 *
 * The public surface of SocialMetricsService that other components call, so they depend
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
 * Contract of SocialMetricsService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SocialMetricsServiceInterface {

	/**
	 * Each network's publications of the last `$days` days, summed.
	 *
	 * One row per network that published in the period, most publications
	 * first: the count, the summed numbers, the follower count of the
	 * network's accounts and the interaction rate (likes, comments and shares
	 * over views, in percent to one decimal; null without views).
	 *
	 * @param int $days The period in days, ending now.
	 *
	 * @return array<int, array<string, mixed>> The per-network rows.
	 *
	 * @spec openspec/specs/social-metrics/spec.md#requirement-social-performance-is-summed-per-network-for-a-period
	 */
	public function networkTotals(int $days = 30): array;

	/**
	 * Pull every published publication's numbers, and every account's follower
	 * count.
	 *
	 * One row failing never stops the rest: the loop records what it could and
	 * leaves the rest alone, because a pull that aborts on the first dead grant
	 * would leave a whole morning's report empty for one bad account.
	 *
	 * @return array{publications: int, accounts: int, failed: int} What the run did.
	 *
	 * @spec openspec/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
	 */
	public function pullAll(): array;

	/**
	 * Publications ranked by engagement rate, highest first.
	 *
	 * The follower count comes off the account row the same pull recorded, so
	 * the page reads publications once and never walks them to fetch their
	 * accounts one at a time (pipelinq#1781).
	 *
	 * @param string $network A network to limit the ranking to, or an empty string for all.
	 * @param int $limit How many rows to return.
	 *
	 * @return array<int, array<string, mixed>> The ranked rows.
	 *
	 * @spec openspec/specs/social-metrics/spec.md#requirement-posts-are-ranked-by-engagement-rate-per-network
	 */
	public function ranking(string $network = '', int $limit = 50): array;
}//end interface
