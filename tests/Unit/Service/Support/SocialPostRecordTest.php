<?php

/**
 * Unit tests for SocialPostRecord.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Support
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Support;

use OCA\Pipelinq\Service\Support\SocialPostRecord;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SocialPostRecord.
 *
 * @covers \OCA\Pipelinq\Service\Support\SocialPostRecord
 */
class SocialPostRecordTest extends TestCase {

	/**
	 * A draft keeps the client's fields and is stamped as a draft by its author.
	 *
	 * @return void
	 */
	public function testDraftedStampsStatusApprovalsAndAuthor(): void {
		$draft = SocialPostRecord::drafted(post: ['body' => 'Hi'], uid: 'alice');

		$this->assertSame(
			expected: ['body' => 'Hi', 'status' => 'draft', 'approvals' => [], 'createdBy' => 'alice'],
			actual: $draft
		);
	}//end testDraftedStampsStatusApprovalsAndAuthor()

	/**
	 * A person editing an agent's draft takes authorship; a person's draft is kept.
	 *
	 * @return void
	 */
	public function testTakenOverOnlyChangesAnAgentsDraft(): void {
		$agent = ['agentAuthored' => true, 'agentAuthoredBy' => 'bot', 'createdBy' => 'bot'];
		$person = ['agentAuthored' => false, 'agentAuthoredBy' => '', 'createdBy' => 'carol'];

		$this->assertSame(
			expected: ['agentAuthored' => false, 'agentAuthoredBy' => '', 'createdBy' => 'bob'],
			actual: SocialPostRecord::takenOver(update: $agent, uid: 'bob')
		);
		$this->assertSame(expected: $person, actual: SocialPostRecord::takenOver(update: $person, uid: 'bob'));
	}//end testTakenOverOnlyChangesAnAgentsDraft()

	/**
	 * A saved post is answered as the post, a failed save as the error.
	 *
	 * @return void
	 */
	public function testSavedAnswer(): void {
		$this->assertSame(expected: ['post' => ['id' => 'p']], actual: SocialPostRecord::savedAnswer(saved: ['id' => 'p']));
		$this->assertSame(
			expected: ['error' => 'The post could not be saved.'],
			actual: SocialPostRecord::savedAnswer(saved: null)
		);
	}//end testSavedAnswer()
}//end class
