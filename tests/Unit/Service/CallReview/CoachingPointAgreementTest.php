<?php

/**
 * Pipelinq CoachingPointAgreementTest.
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
 * @spec openspec/changes/contact-moments-call-review/specs/call-review/spec.md#requirement-the-agent-agrees-the-coaching-points-req-crv-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\CallReview;

use OCA\Pipelinq\Service\CallReview\CoachingPointAgreement;
use PHPUnit\Framework\TestCase;

/**
 * Who may agree a coaching point, and what agreeing it stores.
 *
 * @covers \OCA\Pipelinq\Service\CallReview\CoachingPointAgreement
 */
class CoachingPointAgreementTest extends TestCase {

	private const REVIEW = [
		'agent'          => 'sanne',
		'reviewer'       => 'lead',
		'status'         => 'shared',
		'coachingPoints' => [
			['text' => 'Name the next step'],
			['text' => 'Close warmly', 'agreedAt' => '2026-11-01T09:00:00+00:00'],
		],
	];

	/**
	 * The agent of a shared review may agree an existing point; an agreed review still accepts it.
	 *
	 * @return void
	 */
	public function testTheAgentMayAgreeAPointOfASharedReview(): void {
		$rule = new CoachingPointAgreement();

		$this->assertNull($rule->refusal(review: self::REVIEW, index: 0, userId: 'sanne'));
		$this->assertNull($rule->refusal(review: ['status' => 'agreed'] + self::REVIEW, index: 1, userId: 'sanne'));
	}//end testTheAgentMayAgreeAPointOfASharedReview()

	/**
	 * Scenario "Agents do not see each other's reviews": anyone but the agent is refused, the reviewer too.
	 *
	 * @return void
	 */
	public function testOnlyTheAgentMayAgree(): void {
		$rule = new CoachingPointAgreement();

		$this->assertSame(CoachingPointAgreement::REFUSED_NOT_THE_AGENT, $rule->refusal(review: self::REVIEW, index: 0, userId: 'mehmet'));
		$this->assertSame(CoachingPointAgreement::REFUSED_NOT_THE_AGENT, $rule->refusal(review: self::REVIEW, index: 0, userId: 'lead'));
		$this->assertSame(CoachingPointAgreement::REFUSED_NOT_THE_AGENT, $rule->refusal(review: ['agent' => ''] + self::REVIEW, index: 0, userId: ''));
	}//end testOnlyTheAgentMayAgree()

	/**
	 * A draft review is not the agent's to agree yet.
	 *
	 * @return void
	 */
	public function testADraftCannotBeAgreed(): void {
		$rule = new CoachingPointAgreement();

		$this->assertSame(CoachingPointAgreement::REFUSED_NOT_SHARED, $rule->refusal(review: ['status' => 'draft'] + self::REVIEW, index: 0, userId: 'sanne'));
		$this->assertSame(CoachingPointAgreement::REFUSED_NOT_SHARED, $rule->refusal(review: array_diff_key(self::REVIEW, ['status' => 1]), index: 0, userId: 'sanne'));
	}//end testADraftCannotBeAgreed()

	/**
	 * A position outside the list, or a list that is not a list of points, is refused.
	 *
	 * @return void
	 */
	public function testAMissingPointIsRefused(): void {
		$rule = new CoachingPointAgreement();

		$this->assertSame(CoachingPointAgreement::REFUSED_NO_SUCH_POINT, $rule->refusal(review: self::REVIEW, index: 2, userId: 'sanne'));
		$this->assertSame(CoachingPointAgreement::REFUSED_NO_SUCH_POINT, $rule->refusal(review: self::REVIEW, index: -1, userId: 'sanne'));
		$this->assertSame(CoachingPointAgreement::REFUSED_NO_SUCH_POINT, $rule->refusal(review: ['coachingPoints' => 'x'] + self::REVIEW, index: 0, userId: 'sanne'));
		$this->assertSame(CoachingPointAgreement::REFUSED_NO_SUCH_POINT, $rule->refusal(review: ['coachingPoints' => ['a' => ['text' => 'x']]] + self::REVIEW, index: 0, userId: 'sanne'));
		$this->assertSame(CoachingPointAgreement::REFUSED_NO_SUCH_POINT, $rule->refusal(review: ['coachingPoints' => ['plain text']] + self::REVIEW, index: 0, userId: 'sanne'));
	}//end testAMissingPointIsRefused()

	/**
	 * Agreeing dates only that point; an agreed point keeps its first date.
	 *
	 * @return void
	 */
	public function testAgreeingDatesOnlyThatPointAndKeepsAFirstDate(): void {
		$rule = new CoachingPointAgreement();
		$now = '2026-11-14T10:00:00+00:00';

		$this->assertSame(
			[['text' => 'Name the next step', 'agreedAt' => $now], self::REVIEW['coachingPoints'][1]],
			$rule->agree(points: self::REVIEW['coachingPoints'], index: 0, now: $now)
		);
		$this->assertSame(self::REVIEW['coachingPoints'], $rule->agree(points: self::REVIEW['coachingPoints'], index: 1, now: $now));
		$this->assertSame(self::REVIEW['coachingPoints'], $rule->agree(points: self::REVIEW['coachingPoints'], index: 5, now: $now));
	}//end testAgreeingDatesOnlyThatPointAndKeepsAFirstDate()
}//end class
