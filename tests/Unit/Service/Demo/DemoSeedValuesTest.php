<?php

/**
 * DemoSeedValues: date placeholders and the list-entry reference links.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Demo
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Demo;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\Service\Demo\DemoSeedValues;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Placeholders resolve at every depth; list entries link to seeded uuids.
 */
#[CoversClass(DemoSeedValues::class)]
class DemoSeedValuesTest extends TestCase {

	/**
	 * `@week:D` is a date in the current ISO week, `@week:DTHH:MM` that day
	 * at that time, also inside nested lists.
	 *
	 * @return void
	 */
	public function testWeekPlaceholdersLandInTheCurrentWeek(): void {
		$monday = (new DateTimeImmutable('monday this week'))->setTime(0, 0);

		$data = (new DemoSeedValues())->resolvePlaceholders(
			data: [
				'startAt'             => '@week:2T09:30',
				'vacations'           => [['startDate' => '@week:0', 'endDate' => '@week:6']],
				'resourceAssignments' => [['startAt' => '@week:4T16:05']],
			]
		);

		self::assertSame(expected: $monday->modify('+2 days')->setTime(9, 30)->format(DateTimeInterface::ATOM), actual: $data['startAt']);
		self::assertSame(expected: $monday->format('Y-m-d'), actual: $data['vacations'][0]['startDate']);
		self::assertSame(expected: $monday->modify('+6 days')->format('Y-m-d'), actual: $data['vacations'][0]['endDate']);
		self::assertSame(
			expected: $monday->modify('+4 days')->setTime(16, 5)->format(DateTimeInterface::ATOM),
			actual: $data['resourceAssignments'][0]['startAt']
		);
	}//end testWeekPlaceholdersLandInTheCurrentWeek()

	/**
	 * The day-offset placeholders keep their meaning; anything that is not
	 * exactly a placeholder is left alone.
	 *
	 * @return void
	 */
	public function testOtherValuesAreLeftAsTheyAre(): void {
		$data = (new DemoSeedValues())->resolvePlaceholders(
			data: [
				'due'      => '@days:3',
				'at'       => '@datetime:-1',
				'day7'     => '@week:7',
				'badClock' => '@week:1T24:00',
				'prose'    => 'see @week:1',
				'count'    => 4,
			]
		);

		self::assertSame(expected: (new DateTimeImmutable())->modify('+3 days')->format('Y-m-d'), actual: $data['due']);
		self::assertMatchesRegularExpression(pattern: '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', string: $data['at']);
		self::assertSame(expected: '@week:7', actual: $data['day7']);
		self::assertSame(expected: '@week:1T24:00', actual: $data['badClock']);
		self::assertSame(expected: 'see @week:1', actual: $data['prose']);
		self::assertSame(expected: 4, actual: $data['count']);
	}//end testOtherValuesAreLeftAsTheyAre()

	/**
	 * Each list entry gets the uuid its key resolves to, and loses the key
	 * whether it resolves or not; entries without a key and non-list fields
	 * are untouched.
	 *
	 * @return void
	 */
	public function testListEntriesLinkToSeededUuids(): void {
		$values = new DemoSeedValues();
		$data   = $values->linkListReferences(
			data: [
				'resourceAssignments' => [
					['resourceKey' => 'res-fatma', 'startAt' => 'a'],
					['resourceKey' => 'res-unknown', 'startAt' => 'b'],
					['resourceId' => 'kept', 'startAt' => 'c'],
					'not-an-entry',
				],
			],
			listField: 'resourceAssignments',
			uuids: ['appointmentResources:res-fatma' => 'uuid-fatma'],
			link: ['resourceKey', 'appointmentResources', 'resourceId'],
		);

		self::assertSame(
			expected: [
				['startAt' => 'a', 'resourceId' => 'uuid-fatma'],
				['startAt' => 'b'],
				['resourceId' => 'kept', 'startAt' => 'c'],
				'not-an-entry',
			],
			actual: $data['resourceAssignments']
		);

		$plain = ['resourceAssignments' => 'none'];
		$link  = ['resourceKey', 'appointmentResources', 'resourceId'];
		self::assertSame(
			expected: $plain,
			actual: $values->linkListReferences(data: $plain, listField: 'resourceAssignments', uuids: [], link: $link)
		);
		self::assertSame(
			expected: [],
			actual: $values->linkListReferences(data: [], listField: 'resourceAssignments', uuids: [], link: $link)
		);
	}//end testListEntriesLinkToSeededUuids()
}//end class
