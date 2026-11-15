<?php

/**
 * Unit tests for small Service value objects.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Repair\RetiredPspKey;
use OCA\Pipelinq\Service\CalendarYear;
use OCA\Pipelinq\Service\ProductCategoryNames;
use OCA\Pipelinq\Service\ReportingWindow;
use OCA\Pipelinq\Service\SegmentRulePosition;
use OCA\Pipelinq\Service\Sla\SlaHeat;
use OCA\Pipelinq\Service\TimedResponse;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;

/**
 * The small value objects that carry a rule of their own.
 */
class ServiceValueObjectsTest extends TestCase {

	/**
	 * A product's category name, the category ref itself, or "Other".
	 *
	 * @return void
	 */
	public function testProductCategoryNames(): void {
		$names = new ProductCategoryNames(
			categoryByProduct: ['p-1' => 'cat-a', 'p-2' => 'cat-unnamed', 'p-3' => ''],
			categoryNames: ['cat-a' => 'Koffie']
		);

		self::assertSame('Koffie', $names->nameOf(productId: 'p-1'));
		self::assertSame('cat-unnamed', $names->nameOf(productId: 'p-2'));
		self::assertSame('Other', $names->nameOf(productId: 'p-3'));
		self::assertSame('Other', $names->nameOf(productId: 'unknown'));
	}//end testProductCategoryNames()

	/**
	 * A reporting window includes both ends and never a missing moment.
	 *
	 * @return void
	 */
	public function testReportingWindow(): void {
		$window = new ReportingWindow(startTs: 100, lastTs: 200);

		self::assertTrue($window->contains(timestamp: 100));
		self::assertTrue($window->contains(timestamp: 200));
		self::assertFalse($window->contains(timestamp: 99));
		self::assertFalse($window->contains(timestamp: 201));
		self::assertFalse($window->contains(timestamp: null));
	}//end testReportingWindow()

	/**
	 * A calendar setting may name several calendars; blanks are dropped.
	 *
	 * @return void
	 */
	public function testCalendarYearNames(): void {
		self::assertSame(['NL', 'Utrecht'], array_values((new CalendarYear(calendarName: ' NL , Utrecht ,, ', year: 2026))->names()));
		self::assertSame([], (new CalendarYear(calendarName: '', year: 2026))->names());
	}//end testCalendarYearNames()

	/**
	 * The SLA heat falls back to the resolution clock.
	 *
	 * @return void
	 */
	public function testSlaHeatKind(): void {
		self::assertSame('resolution', (new SlaHeat(consumed: 0.8, hottestKind: ''))->kind());
		self::assertSame('response', (new SlaHeat(consumed: 0.8, hottestKind: 'response'))->kind());
	}//end testSlaHeatKind()

	/**
	 * A timed response reads the status as an integer.
	 *
	 * @return void
	 */
	public function testTimedResponseStatus(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(204);

		$timed = new TimedResponse(response: $response, durationMs: 85);
		self::assertSame(204, $timed->status());
		self::assertSame(85, $timed->durationMs);
	}//end testTimedResponseStatus()

	/**
	 * A retired PSP key is the provider's payment config key.
	 *
	 * @return void
	 */
	public function testRetiredPspKey(): void {
		self::assertSame('payment_provider.mollie.apiKey', (new RetiredPspKey(provider: 'mollie', field: 'apiKey'))->configKey());
	}//end testRetiredPspKey()

	/**
	 * A child position extends the path and keeps the properties.
	 *
	 * @return void
	 */
	public function testSegmentRulePositionChild(): void {
		$root = new SegmentRulePosition(path: 'rules', properties: ['city' => 'string']);
		$child = $root->child(segment: '[0].and');

		self::assertSame('rules[0].and', $child->path);
		self::assertSame(['city' => 'string'], $child->properties);
		self::assertSame('rules', $root->path);
	}//end testSegmentRulePositionChild()
}//end class
