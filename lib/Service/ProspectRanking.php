<?php

/**
 * Pipelinq ProspectRanking.
 *
 * Ranks fetched prospects: existing clients out, scored, inactive out.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/prospect-discovery/spec.md#requirement-ideal-customer-profile-configuration
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * Leaves out the prospects that are already clients, scores and sorts the
 * rest, and leaves out inactive companies unless the profile keeps them.
 *
 * @spec openspec/specs/prospect-discovery/spec.md#requirement-ideal-customer-profile-configuration
 */
class ProspectRanking {
	/**
	 * Constructor.
	 *
	 * @param ProspectScoringService $scoring The scoring service.
	 * @param ExistingClientNames    $clients The existing clients.
	 */
	public function __construct(
		private ProspectScoringService $scoring,
		private ExistingClientNames $clients,
	) {
	}//end __construct()

	/**
	 * The prospects, existing clients left out, scored and sorted.
	 *
	 * @param array<int, array<string, mixed>> $prospects The fetched prospects.
	 * @param array<string, mixed>             $criteria  The ICP criteria.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/prospect-discovery/spec.md#requirement-ideal-customer-profile-configuration
	 */
	public function rank(array $prospects, array $criteria): array {
		$prospects = $this->scoring->scoreAll(prospects: $this->clients->exclude(prospects: $prospects), criteria: $criteria);
		if (($criteria['excludeInactive'] ?? true) !== true) {
			return $prospects;
		}

		return array_values(
			array: array_filter(
				array: $prospects,
				callback: static fn (array $prospect): bool => ($prospect['isActive'] ?? true) === true
			)
		);
	}//end rank()

	/**
	 * The prospects without the ones that have since become clients.
	 *
	 * @param array<int, array<string, mixed>> $prospects The prospects.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/prospect-discovery/spec.md#requirement-ideal-customer-profile-configuration
	 */
	public function withoutClients(array $prospects): array {
		return $this->clients->exclude(prospects: $prospects);
	}//end withoutClients()
}//end class
