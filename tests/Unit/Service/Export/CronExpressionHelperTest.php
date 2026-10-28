<?php

/**
 * Unit tests for CronExpressionHelper.
 *
 * Verifies the 5-field cron parser that drives scheduled export enqueueing:
 * validity checks, step/range/list field matching, the day-of-month OR
 * day-of-week semantics, Sunday-as-0-or-7 handling, and a human-readable
 * schedule preview (nl + en).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Export
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Export;

use DateTimeImmutable;
use OCA\Pipelinq\Service\Export\CronExpressionHelper;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CronExpressionHelper.
 */
class CronExpressionHelperTest extends TestCase {
	/**
	 * The helper under test.
	 *
	 * @var CronExpressionHelper
	 */
	private CronExpressionHelper $cron;

	/**
	 * Instantiate the helper.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->cron = new CronExpressionHelper();
	}//end setUp()

	/**
	 * A well-formed 5-field expression is valid; a malformed one is not.
	 *
	 * @return void
	 */
	public function testIsValid(): void {
		$this->assertTrue($this->cron->isValid(expression: '0 2 * * *'));
		$this->assertTrue($this->cron->isValid(expression: '*/15 * * * *'));
		$this->assertFalse($this->cron->isValid(expression: '0 2 * *'));
		$this->assertFalse($this->cron->isValid(expression: 'not a cron'));
		$this->assertFalse($this->cron->isValid(expression: '99 2 * * *'));
	}//end testIsValid()

	/**
	 * `0 2 * * *` is due at 02:00 and not at 03:00.
	 *
	 * @return void
	 */
	public function testIsDueAtScheduledMinute(): void {
		$expression = '0 2 * * *';

		$this->assertTrue($this->cron->isDue(expression: $expression, when: new DateTimeImmutable('2026-06-03 02:00:00'))
		);
		$this->assertFalse($this->cron->isDue(expression: $expression, when: new DateTimeImmutable('2026-06-03 03:00:00'))
		);
	}//end testIsDueAtScheduledMinute()

	/**
	 * A step expression matches every Nth minute.
	 *
	 * @return void
	 */
	public function testIsDueStepField(): void {
		$expression = '*/15 * * * *';

		$this->assertTrue($this->cron->isDue(expression: $expression, when: new DateTimeImmutable('2026-06-03 10:30:00'))
		);
		$this->assertFalse($this->cron->isDue(expression: $expression, when: new DateTimeImmutable('2026-06-03 10:31:00'))
		);
	}//end testIsDueStepField()

	/**
	 * Day-of-week matching treats Sunday as both 0 and 7.
	 *
	 * @return void
	 */
	public function testIsDueDayOfWeekSunday(): void {
		// 2026-06-07 is a Sunday.
		$sunday = new DateTimeImmutable('2026-06-07 00:00:00');

		$this->assertTrue($this->cron->isDue(expression: '0 0 * * 0', when: $sunday));
		$this->assertTrue($this->cron->isDue(expression: '0 0 * * 7', when: $sunday));
	}//end testIsDueDayOfWeekSunday()

	/**
	 * An invalid expression is never due.
	 *
	 * @return void
	 */
	public function testInvalidExpressionNeverDue(): void {
		$this->assertFalse($this->cron->isDue(expression: 'garbage', when: new DateTimeImmutable('2026-06-03 02:00:00'))
		);
	}//end testInvalidExpressionNeverDue()

	/**
	 * The human-readable describe() returns non-empty text in both locales.
	 *
	 * @return void
	 */
	public function testDescribeProducesText(): void {
		$this->assertNotSame('', $this->cron->describe(expression: '0 2 * * *', locale: 'en'));
		$this->assertNotSame('', $this->cron->describe(expression: '0 2 * * *', locale: 'nl'));
	}//end testDescribeProducesText()

	/**
	 * The next run of a nightly job is tonight at 02:00, or tomorrow once it is past.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bi-export-board/specs/bi-export-board/spec.md#requirement-a-job-shows-its-last-run-and-its-next-run
	 */
	public function testNextRunAfterNightly(): void {
		$before = $this->cron->nextRunAfter(expression: '0 2 * * *', from: new DateTimeImmutable('2026-10-07 01:30:00'));
		$this->assertSame(expected: '2026-10-07 02:00', actual: $before?->format('Y-m-d H:i'));

		$after = $this->cron->nextRunAfter(expression: '0 2 * * *', from: new DateTimeImmutable('2026-10-07 02:00:00'));
		$this->assertSame(expected: '2026-10-08 02:00', actual: $after?->format('Y-m-d H:i'));
	}//end testNextRunAfterNightly()

	/**
	 * Hourly, a weekday and a shortcut each land on their next minute.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bi-export-board/specs/bi-export-board/spec.md#requirement-a-job-shows-its-last-run-and-its-next-run
	 */
	public function testNextRunAfterHourlyWeeklyAndShortcut(): void {
		// Wednesday 7 October 2026, 10:01.
		$from = new DateTimeImmutable('2026-10-07 10:01:00');
		$hourly = $this->cron->nextRunAfter(expression: '@hourly', from: $from);
		$this->assertSame(expected: '2026-10-07 11:00', actual: $hourly?->format('Y-m-d H:i'));

		// Monday 03:00.
		$weekly = $this->cron->nextRunAfter(expression: '0 3 * * 1', from: $from);
		$this->assertSame(expected: '2026-10-12 03:00', actual: $weekly?->format('Y-m-d H:i'));

		$monthly = $this->cron->nextRunAfter(expression: '@monthly', from: $from);
		$this->assertSame(expected: '2026-11-01 00:00', actual: $monthly?->format('Y-m-d H:i'));
	}//end testNextRunAfterHourlyWeeklyAndShortcut()

	/**
	 * An invalid expression, or one that never fires within the horizon, has no next run.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bi-export-board/specs/bi-export-board/spec.md#requirement-a-job-shows-its-last-run-and-its-next-run
	 */
	public function testNextRunAfterNone(): void {
		$from = new DateTimeImmutable('2026-10-07 10:00:00');
		$this->assertNull(actual: $this->cron->nextRunAfter(expression: 'not a cron', from: $from));
		$this->assertNull(actual: $this->cron->nextRunAfter(expression: '0 0 31 2 *', from: $from));
	}//end testNextRunAfterNone()
}//end class
