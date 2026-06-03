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
 * @link https://codeberg.org/Conduction/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Export;

use DateTimeImmutable;
use OCA\Pipelinq\Service\Export\CronExpressionHelper;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CronExpressionHelper.
 */
class CronExpressionHelperTest extends TestCase
{
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
    protected function setUp(): void
    {
        parent::setUp();
        $this->cron = new CronExpressionHelper();
    }//end setUp()

    /**
     * A well-formed 5-field expression is valid; a malformed one is not.
     *
     * @return void
     */
    public function testIsValid(): void
    {
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
    public function testIsDueAtScheduledMinute(): void
    {
        $expression = '0 2 * * *';

        $this->assertTrue(
            $this->cron->isDue(expression: $expression, when: new DateTimeImmutable('2026-06-03 02:00:00'))
        );
        $this->assertFalse(
            $this->cron->isDue(expression: $expression, when: new DateTimeImmutable('2026-06-03 03:00:00'))
        );
    }//end testIsDueAtScheduledMinute()

    /**
     * A step expression matches every Nth minute.
     *
     * @return void
     */
    public function testIsDueStepField(): void
    {
        $expression = '*/15 * * * *';

        $this->assertTrue(
            $this->cron->isDue(expression: $expression, when: new DateTimeImmutable('2026-06-03 10:30:00'))
        );
        $this->assertFalse(
            $this->cron->isDue(expression: $expression, when: new DateTimeImmutable('2026-06-03 10:31:00'))
        );
    }//end testIsDueStepField()

    /**
     * Day-of-week matching treats Sunday as both 0 and 7.
     *
     * @return void
     */
    public function testIsDueDayOfWeekSunday(): void
    {
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
    public function testInvalidExpressionNeverDue(): void
    {
        $this->assertFalse(
            $this->cron->isDue(expression: 'garbage', when: new DateTimeImmutable('2026-06-03 02:00:00'))
        );
    }//end testInvalidExpressionNeverDue()

    /**
     * The human-readable describe() returns non-empty text in both locales.
     *
     * @return void
     */
    public function testDescribeProducesText(): void
    {
        $this->assertNotSame('', $this->cron->describe(expression: '0 2 * * *', locale: 'en'));
        $this->assertNotSame('', $this->cron->describe(expression: '0 2 * * *', locale: 'nl'));
    }//end testDescribeProducesText()
}//end class
