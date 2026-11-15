<?php

/**
 * Unit tests for SurveyInvitationFigures.
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

use DateTimeImmutable;
use OCA\Pipelinq\Service\SurveyInvitationFigures;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SurveyInvitationFigures: the response rate, the per-channel split and the scheduled-date window.
 *
 * @covers \OCA\Pipelinq\Service\SurveyInvitationFigures
 */
class SurveyInvitationFiguresTest extends TestCase {

	/**
	 * Delivered is sent plus responded; the rate is responded over delivered, to one decimal.
	 *
	 * @return void
	 */
	public function testRateCountsDeliveredAndResponded(): void {
		$invitations = [
			['status' => 'sent'],
			['status' => 'sent'],
			['status' => 'responded'],
			['status' => 'suppressed'],
			['status' => 'failed'],
			['status' => 'failed'],
			['status' => 'queued'],
			[],
		];

		self::assertSame(
			expected: ['delivered' => 3, 'responded' => 1, 'rate' => 33.3, 'suppressed' => 1, 'failed' => 2],
			actual: (new SurveyInvitationFigures())->rate(invitations: $invitations)
		);
	}//end testRateCountsDeliveredAndResponded()

	/**
	 * Nothing delivered gives a zero rate rather than a division error.
	 *
	 * @return void
	 */
	public function testRateIsZeroWhenNothingWasDelivered(): void {
		self::assertSame(
			expected: ['delivered' => 0, 'responded' => 0, 'rate' => 0.0, 'suppressed' => 1, 'failed' => 0],
			actual: (new SurveyInvitationFigures())->rate(invitations: [['status' => 'suppressed']])
		);
	}//end testRateIsZeroWhenNothingWasDelivered()

	/**
	 * Invitations are grouped by trimmed channel, a blank one as unknown, sorted by channel name.
	 *
	 * @return void
	 */
	public function testByChannelGroupsAndSortsChannels(): void {
		$result = (new SurveyInvitationFigures())->byChannel(
			invitations: [
				['channel' => ' sms ', 'status' => 'responded'],
				['channel' => 'email', 'status' => 'sent'],
				['channel' => 'email', 'status' => 'responded'],
				['status' => 'sent'],
			]
		);

		self::assertSame(expected: ['email', 'sms', 'unknown'], actual: array_keys(array: $result));
		self::assertSame(expected: 50.0, actual: $result['email']['rate']);
		self::assertSame(expected: 2, actual: $result['email']['delivered']);
		self::assertSame(expected: 100.0, actual: $result['sms']['rate']);
		self::assertSame(expected: 0.0, actual: $result['unknown']['rate']);
	}//end testByChannelGroupsAndSortsChannels()

	/**
	 * Only invitations scheduled inside [now - days, now] are kept; blank, unparsable and future ones are dropped.
	 *
	 * @return void
	 */
	public function testWithinDaysKeepsTheWindowOnly(): void {
		$now         = new DateTimeImmutable('2026-03-10T12:00:00+00:00');
		$invitations = [
			'old'    => ['id' => 1, 'scheduledFor' => '2026-03-01T12:00:00+00:00'],
			'edge'   => ['id' => 2, 'scheduledFor' => '2026-03-03T12:00:00+00:00'],
			'inside' => ['id' => 3, 'scheduledFor' => '2026-03-09T08:00:00+00:00'],
			'future' => ['id' => 4, 'scheduledFor' => '2026-03-11T00:00:00+00:00'],
			'blank'  => ['id' => 5, 'scheduledFor' => '  '],
			'bad'    => ['id' => 6, 'scheduledFor' => 'not-a-date'],
			'none'   => ['id' => 7],
		];

		$kept = (new SurveyInvitationFigures())->withinDays(invitations: $invitations, days: 7, now: $now);

		self::assertSame(expected: [2, 3], actual: array_column(array: $kept, column_key: 'id'));
	}//end testWithinDaysKeepsTheWindowOnly()

	/**
	 * A non-positive window keeps every invitation, re-indexed.
	 *
	 * @return void
	 */
	public function testWithinDaysWithoutAWindowKeepsAll(): void {
		$invitations = ['a' => ['id' => 1], 'b' => ['id' => 2, 'scheduledFor' => 'bad']];

		self::assertSame(
			expected: [['id' => 1], ['id' => 2, 'scheduledFor' => 'bad']],
			actual: (new SurveyInvitationFigures())->withinDays(invitations: $invitations, days: 0)
		);
	}//end testWithinDaysWithoutAWindowKeepsAll()
}//end class
