<?php
/**
 * Example data for the Pq list boards: each list holds the rows its board
 * draws (PqJourneys, PqProducten, PqSjablonen, PqKassadiensten, PqAfspraken),
 * and the paged lists hold at least one full page (PqCampagnes, PqContacten,
 * PqOrganisaties).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Settings
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The seeded row counts of the Pq list boards.
 */
class ZuiddrechtListRowsTest extends TestCase {

	/**
	 * Every object of the Zuiddrecht set, the main file and its parts.
	 *
	 * @return array<int, array<string, mixed>> The objects.
	 */
	private function objects(): array {
		$dir     = __DIR__.'/../../../lib/Settings/example-sets';
		$files   = array_merge([$dir.'/zuiddrecht.json'], glob($dir.'/zuiddrecht.d/*.json'));
		$objects = [];
		foreach ($files as $file) {
			$part = json_decode((string)file_get_contents($file), true);
			$this->assertIsArray(actual: $part, message: $file);
			$objects = array_merge($objects, ($part['objects'] ?? []));
		}

		return $objects;
	}//end objects()

	/**
	 * The data of every pipelinq object of one schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, array<string, mixed>> The data.
	 */
	private function rows(string $schema): array {
		$rows = [];
		foreach ($this->objects() as $object) {
			if (($object['register'] ?? '') === 'pipelinq' && ($object['schema'] ?? '') === $schema) {
				$rows[] = $object['data'];
			}
		}

		return $rows;
	}//end rows()

	/**
	 * The lists without a pager hold exactly the board's rows: 5 journeys,
	 * 14 products, 10 templates and 5 cash shifts.
	 *
	 * @return void
	 */
	public function testUnpagedListsHoldTheBoardRows(): void {
		$this->assertCount(expectedCount: 5, haystack: $this->rows(schema: 'journey'));
		$this->assertCount(expectedCount: 14, haystack: $this->rows(schema: 'product'));
		$this->assertCount(expectedCount: 10, haystack: $this->rows(schema: 'campaignTemplate'));
		$this->assertCount(expectedCount: 5, haystack: $this->rows(schema: 'cashShift'));
	}//end testUnpagedListsHoldTheBoardRows()

	/**
	 * PqProducten draws Club-app as a draft ("Concept").
	 *
	 * @return void
	 */
	public function testClubAppIsADraft(): void {
		$names = array_column($this->rows(schema: 'product'), 'status', 'name');
		$this->assertSame(expected: 'draft', actual: $names['Club-app, basis']);
		$this->assertSame(expected: 'inactive', actual: $names['Archief scannen, per doos']);
	}//end testClubAppIsADraft()

	/**
	 * This week (the install day and the six after it) holds the 23
	 * bookings PqAfspraken counts, 4 of them Pieter's and 3 awaiting a
	 * deposit, and its first page of 8 starts with the board's rows.
	 *
	 * @return void
	 */
	public function testThisWeekHoldsTheBoardBookings(): void {
		$week = [];
		foreach ($this->rows(schema: 'appointmentBooking') as $booking) {
			$this->assertMatchesRegularExpression(pattern: '/^\{\{datetime:(-?\d+) (\d\d:\d\d)\}\}$/', string: $booking['startAt']);
			preg_match('/^\{\{datetime:(-?\d+) (\d\d:\d\d)\}\}$/', $booking['startAt'], $match);
			$day = (int)$match[1];
			if ($day >= 0 && $day <= 6) {
				$week[sprintf('%d %s', $day, $match[2])] = $booking;
			}
		}

		ksort($week, SORT_NATURAL);
		$this->assertCount(expectedCount: 23, haystack: $week);
		$this->assertCount(expectedCount: 4, haystack: array_filter($week, fn ($b) => $b['assignee'] === '{{cast:pieter.uid}}'));
		$this->assertCount(expectedCount: 3, haystack: array_filter($week, fn ($b) => $b['status'] === 'pending-deposit'));
		$this->assertSame(
			expected: [
				'Paspoort ophalen',
				'Parkeervergunning tweede auto',
				'Gesprek over de klacht',
				'Subsidie straatfeest',
				'Locatiebezoek Parkstraat',
				'Rijbewijs ophalen',
				'Klacht geluidsoverlast vervolg',
			],
			actual: array_slice(array_column($week, 'subject'), 0, 7)
		);
	}//end testThisWeekHoldsTheBoardBookings()

	/**
	 * The paged lists fill their first page: 5 campaigns, 5 contacts and
	 * 8 organisations.
	 *
	 * @return void
	 */
	public function testPagedListsFillTheirFirstPage(): void {
		$this->assertGreaterThanOrEqual(expected: 5, actual: count($this->rows(schema: 'campaign')));
		$this->assertGreaterThanOrEqual(expected: 5, actual: count($this->rows(schema: 'contact')));
		$organisations = array_filter($this->rows(schema: 'client'), fn ($c) => ($c['type'] ?? '') === 'organization');
		$this->assertGreaterThanOrEqual(expected: 8, actual: count($organisations));
	}//end testPagedListsFillTheirFirstPage()
}//end class
