<?php

/**
 * Pipelinq DemoMarketingSeeder.
 *
 * Seeds and removes the marketing half of the demo set (journeys, social,
 * Search Console) for DemoSeedService, one domain seeder each.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Demo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Demo;

/**
 * The marketing demo data, seeded after (and removed before) the CRM objects.
 *
 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
 */
class DemoMarketingSeeder {
	/**
	 * Constructor.
	 *
	 * @param DemoJourneySeeder $journeySeeder Demo journeys and their runs.
	 * @param DemoSocialSeeder $socialSeeder Demo social accounts, posts and publications.
	 * @param DemoSearchSeeder $searchSeeder Demo Search Console rows and keyword targets.
	 */
	public function __construct(
		private readonly DemoJourneySeeder $journeySeeder,
		private readonly DemoSocialSeeder $socialSeeder,
		private readonly DemoSearchSeeder $searchSeeder,
	) {
	}//end __construct()

	/**
	 * Seed the journeys, social data and Search Console data of the seed file.
	 *
	 * @param array<string, mixed> $definitions The whole seed file.
	 * @param array<string, string> $uuids Section-local keys mapped to the CRM objects already seeded.
	 *
	 * @return array{created: array<string, int>, skipped: array<string, int>} Counts to merge into the seed result.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 */
	public function seed(array $definitions, array $uuids): array {
		$journeys = $this->journeySeeder->seed(definitions: ($definitions['journeys'] ?? []), uuids: $uuids);
		$social = $this->socialSeeder->seed(social: ($definitions['social'] ?? []));
		$search = $this->searchSeeder->seed(search: ($definitions['search'] ?? []));

		return [
			'created' => [
				'journeys' => $journeys['created'],
				'journeyRuns' => $journeys['runs'],
				'socialAccounts' => $social['accounts'],
				'socialPosts' => $social['posts'],
				'socialPublications' => $social['publications'],
				'searchQueryRows' => $search['rows'],
				'keywordTargets' => $search['targets'],
			],
			'skipped' => [
				'journeys' => $journeys['skipped'],
				'social' => $social['skipped'],
				'search' => $search['skipped'],
			],
		];
	}//end seed()

	/**
	 * Remove the demo journeys, social data and Search Console data.
	 *
	 * @param array<string, mixed> $definitions The whole seed file.
	 *
	 * @return array<string, int> Removed counts, keyed as in the removal result.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 */
	public function remove(array $definitions): array {
		$journeys = $this->journeySeeder->remove(definitions: ($definitions['journeys'] ?? []));
		$social = $this->socialSeeder->remove(social: ($definitions['social'] ?? []));
		$search = $this->searchSeeder->remove(search: ($definitions['search'] ?? []));

		return [
			'journeys' => $journeys['journeys'],
			'journeyRuns' => $journeys['runs'],
			'socialPublications' => $social['publications'],
			'socialPosts' => $social['posts'],
			'socialAccounts' => $social['accounts'],
			'searchQueryRows' => $search['rows'],
			'keywordTargets' => $search['targets'],
		];
	}//end remove()
}//end class
