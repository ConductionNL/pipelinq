<?php

/**
 * Unit tests for DeadlineService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Avg
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
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

namespace OCA\Pipelinq\Tests\Unit\Service\Avg;

use DateTimeImmutable;
use OCA\Pipelinq\Service\Avg\DeadlineService;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/AvgTestSupport.php';

/**
 * Tests for DeadlineService.
 *
 * The base deadline maths now ADOPTS OpenRegister's canonical EU art-12(3)
 * mechanic via OrGdprBridge: ONE MONTH from receipt for the base term and a
 * single TWO-MONTH extension (not the earlier NL 30/60-day approximations).
 * These tests assert the NEW (OR) behaviour.
 */
class DeadlineServiceTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var DeadlineService
     */
    private DeadlineService $service;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->service = new DeadlineService(
            orGdpr: OrGdprBridgeFactory::build(new FakeOrGdpr())
        );
    }//end setUp()

    /**
     * The base deadline is ONE MONTH after intake (EU art-12), end-of-day.
     *
     * @return void
     */
    public function testComputeDeadlineAddsOneMonth(): void
    {
        $intake   = new DateTimeImmutable('2026-04-08T11:14:00+02:00');
        $deadline = $this->service->computeDeadline(submittedAt: $intake);

        $this->assertSame('2026-05-08', $deadline->format('Y-m-d'));
        $this->assertSame('23:59:59', $deadline->format('H:i:s'));
    }//end testComputeDeadlineAddsOneMonth()

    /**
     * The single extension adds TWO MONTHS on top of the one-month base term
     * (EU art-12(3)): intake 2026-04-08 -> base 2026-05-08 -> extended 2026-07-08.
     *
     * @return void
     */
    public function testComputeDeadlineWithExtension(): void
    {
        $intake   = new DateTimeImmutable('2026-04-08T11:14:00+02:00');
        $deadline = $this->service->computeDeadline(
            submittedAt: $intake,
            extensionDays: DeadlineService::EXTENSION_DAYS
        );

        $this->assertSame('2026-07-08', $deadline->format('Y-m-d'));
    }//end testComputeDeadlineWithExtension()

    /**
     * Urgency is green well before the deadline, yellow within 7 days, red within
     * 72h, and red when breached.
     *
     * @return void
     */
    public function testUrgencyClassification(): void
    {
        $deadline = new DateTimeImmutable('2026-05-08T23:59:59+02:00');

        $this->assertSame('green', $this->service->urgency(
            deadline: $deadline,
            now: new DateTimeImmutable('2026-04-20T12:00:00+02:00')
        ));
        $this->assertSame('yellow', $this->service->urgency(
            deadline: $deadline,
            now: new DateTimeImmutable('2026-05-04T12:00:00+02:00')
        ));
        $this->assertSame('red', $this->service->urgency(
            deadline: $deadline,
            now: new DateTimeImmutable('2026-05-07T12:00:00+02:00')
        ));
        $this->assertSame('red', $this->service->urgency(
            deadline: $deadline,
            now: new DateTimeImmutable('2026-05-10T12:00:00+02:00')
        ));
    }//end testUrgencyClassification()

    /**
     * Escalation fires inside 72h and breach is detected past the deadline.
     *
     * @return void
     */
    public function testEscalationAndBreach(): void
    {
        $deadline = new DateTimeImmutable('2026-05-08T23:59:59+02:00');

        $this->assertFalse($this->service->shouldEscalate(
            deadline: $deadline,
            now: new DateTimeImmutable('2026-05-04T00:00:00+02:00')
        ));
        $this->assertTrue($this->service->shouldEscalate(
            deadline: $deadline,
            now: new DateTimeImmutable('2026-05-07T00:00:00+02:00')
        ));
        $this->assertFalse($this->service->isBreached(
            deadline: $deadline,
            now: new DateTimeImmutable('2026-05-08T00:00:00+02:00')
        ));
        $this->assertTrue($this->service->isBreached(
            deadline: $deadline,
            now: new DateTimeImmutable('2026-05-09T00:00:00+02:00')
        ));
    }//end testEscalationAndBreach()

    /**
     * The 7-day reminder is due exactly 7 days before the deadline day.
     *
     * @return void
     */
    public function testReminderDueExactlySevenDaysOut(): void
    {
        $deadline = new DateTimeImmutable('2026-05-08T23:59:59+02:00');

        $this->assertTrue($this->service->isReminderDue(
            deadline: $deadline,
            now: new DateTimeImmutable('2026-05-01T09:00:00+02:00')
        ));
        $this->assertFalse($this->service->isReminderDue(
            deadline: $deadline,
            now: new DateTimeImmutable('2026-05-02T09:00:00+02:00')
        ));
    }//end testReminderDueExactlySevenDaysOut()
}//end class
