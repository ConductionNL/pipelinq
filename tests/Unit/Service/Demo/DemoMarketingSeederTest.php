<?php

/**
 * Unit tests for DemoMarketingSeeder.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Demo
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Demo;

use OCA\Pipelinq\Service\Demo\DemoJourneySeeder;
use OCA\Pipelinq\Service\Demo\DemoMarketingSeeder;
use OCA\Pipelinq\Service\Demo\DemoSearchSeeder;
use OCA\Pipelinq\Service\Demo\DemoSocialSeeder;
use PHPUnit\Framework\TestCase;

/**
 * Tests that DemoMarketingSeeder puts each seeder's count under the right
 * result key. Every stub returns a distinct number, so a swapped mapping fails.
 *
 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
 */
class DemoMarketingSeederTest extends TestCase {
	/**
	 * Build the seeder over stubs that return distinct counts.
	 *
	 * @return DemoMarketingSeeder
	 */
	private function seeder(): DemoMarketingSeeder {
		$journeys = $this->createMock(DemoJourneySeeder::class);
		$journeys->method('seed')->willReturn(['created' => 1, 'runs' => 2, 'skipped' => 3]);
		$journeys->method('remove')->willReturn(['journeys' => 11, 'runs' => 12]);

		$social = $this->createMock(DemoSocialSeeder::class);
		$social->method('seed')->willReturn(['accounts' => 4, 'posts' => 5, 'publications' => 6, 'skipped' => 7]);
		$social->method('remove')->willReturn(['accounts' => 13, 'posts' => 14, 'publications' => 15]);

		$search = $this->createMock(DemoSearchSeeder::class);
		$search->method('seed')->willReturn(['rows' => 8, 'targets' => 9, 'skipped' => 10]);
		$search->method('remove')->willReturn(['rows' => 16, 'targets' => 17]);

		return new DemoMarketingSeeder(journeySeeder: $journeys, socialSeeder: $social, searchSeeder: $search);
	}//end seeder()

	/**
	 * Each seed count lands under its own created or skipped key.
	 *
	 * @return void
	 */
	public function testSeedMapsEveryCountToItsKey(): void {
		$result = $this->seeder()->seed(definitions: [], uuids: []);

		self::assertSame(
			[
				'journeys' => 1,
				'journeyRuns' => 2,
				'socialAccounts' => 4,
				'socialPosts' => 5,
				'socialPublications' => 6,
				'searchQueryRows' => 8,
				'keywordTargets' => 9,
			],
			$result['created']
		);
		self::assertSame(['journeys' => 3, 'social' => 7, 'search' => 10], $result['skipped']);
	}//end testSeedMapsEveryCountToItsKey()

	/**
	 * Each removal count lands under its own key.
	 *
	 * @return void
	 */
	public function testRemoveMapsEveryCountToItsKey(): void {
		self::assertSame(
			[
				'journeys' => 11,
				'journeyRuns' => 12,
				'socialPublications' => 15,
				'socialPosts' => 14,
				'socialAccounts' => 13,
				'searchQueryRows' => 16,
				'keywordTargets' => 17,
			],
			$this->seeder()->remove(definitions: [])
		);
	}//end testRemoveMapsEveryCountToItsKey()
}//end class
