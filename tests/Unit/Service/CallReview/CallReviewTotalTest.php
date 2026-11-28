<?php

/**
 * Pipelinq CallReviewTotalTest.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\CallReview
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/contact-moments-call-review/specs/call-review/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\CallReview;

use OCA\Pipelinq\Service\CallReview\CallReviewTotal;
use PHPUnit\Framework\TestCase;

/**
 * The weighted total of a call review and when it is agreed.
 *
 * @covers \OCA\Pipelinq\Service\CallReview\CallReviewTotal
 */
class CallReviewTotalTest extends TestCase {

	private const CRITERIA = [
		['key' => 'greeting', 'label' => 'Greeting', 'weight' => 1, 'scale' => 5],
		['key' => 'answer', 'label' => 'Answer', 'weight' => 2, 'scale' => 5],
		['key' => 'closing', 'label' => 'Closing', 'weight' => 1, 'scale' => 5],
	];

	/**
	 * Scenario "A team lead reviews a call": greeting 4, answer 3, closing 5
	 * on weights 1, 2, 1 give (4 + 6 + 5) / 4 = 3.75, shown as 3.8.
	 *
	 * @return void
	 */
	public function testTheTotalIsTheWeightedMean(): void {
		$scores = [['key' => 'greeting', 'score' => 4], ['key' => 'answer', 'score' => 3], ['key' => 'closing', 'score' => 5]];

		$this->assertSame(3.8, (new CallReviewTotal())->totalOf(criteria: self::CRITERIA, scores: $scores));
	}//end testTheTotalIsTheWeightedMean()

	/**
	 * Only scored criteria of the scorecard count; a score outside the scale or
	 * for an unknown key is ignored, and a criterion without a weight weighs 1.
	 *
	 * @return void
	 */
	public function testOnlyValidScoresOnTheScorecardCount(): void {
		$criteria = [['key' => 'greeting', 'scale' => 3], ['key' => 'answer', 'weight' => 3]];
		$scores = [
			['key' => 'greeting', 'score' => 2],
			['key' => 'answer', 'score' => 9],
			['key' => 'unknown', 'score' => 5],
			['key' => '', 'score' => 1],
			['key' => 'closing', 'score' => 'n/a'],
		];

		$this->assertSame(2.0, (new CallReviewTotal())->totalOf(criteria: $criteria, scores: $scores));
	}//end testOnlyValidScoresOnTheScorecardCount()

	/**
	 * Without a scored criterion, or with only zero weights, there is no total.
	 *
	 * @return void
	 */
	public function testNoTotalWithoutAScoredCriterion(): void {
		$rules = new CallReviewTotal();

		$this->assertNull($rules->totalOf(criteria: self::CRITERIA, scores: []));
		$this->assertNull($rules->totalOf(criteria: [], scores: [['key' => 'greeting', 'score' => 4]]));
		$this->assertNull($rules->totalOf(criteria: [['key' => 'greeting', 'weight' => 0]], scores: [['key' => 'greeting', 'score' => 4]]));
	}//end testNoTotalWithoutAScoredCriterion()

	/**
	 * Scenario "Sanne agrees both points": a shared review with every point
	 * agreed is agreed; one open point keeps it shared; a draft stays a draft.
	 *
	 * @return void
	 */
	public function testASharedReviewIsAgreedWhenEveryPointIs(): void {
		$rules = new CallReviewTotal();
		$both = [['text' => 'Noem je naam', 'agreedAt' => '2026-11-20T10:00:00+01:00'], ['text' => 'Vat samen', 'agreedAt' => '2026-11-20T10:01:00+01:00']];
		$one = [['text' => 'Noem je naam', 'agreedAt' => '2026-11-20T10:00:00+01:00'], ['text' => 'Vat samen', 'agreedAt' => ' ']];

		$this->assertSame('agreed', $rules->statusOf(status: 'shared', coachingPoints: $both));
		$this->assertSame('shared', $rules->statusOf(status: 'shared', coachingPoints: $one));
		$this->assertSame('shared', $rules->statusOf(status: 'shared', coachingPoints: []));
		$this->assertSame('draft', $rules->statusOf(status: 'draft', coachingPoints: $both));
		$this->assertSame('agreed', $rules->statusOf(status: 'agreed', coachingPoints: $one));
	}//end testASharedReviewIsAgreedWhenEveryPointIs()
}//end class
