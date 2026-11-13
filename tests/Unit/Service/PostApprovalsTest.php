<?php

/**
 * Unit tests for PostApprovals.
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

use OCA\Pipelinq\Service\PostApprovals;
use OCA\Pipelinq\Service\PostReview;
use OCA\Pipelinq\Service\ReviewVerdict;
use PHPUnit\Framework\TestCase;

/**
 * PostApprovals records reviewers' decisions on a social post.
 */
class PostApprovalsTest extends TestCase {

	/**
	 * An entry names the reviewer, the decision, the note and the moment.
	 *
	 * @return void
	 */
	public function testAnEntry(): void {
		$entry = (new PostApprovals())->entryFor(review: new PostReview(postId: 'p-1', uid: 'anna'), verdict: new ReviewVerdict(decision: 'approved', note: 'Mooi'));

		self::assertSame('anna', $entry['userId']);
		self::assertSame('approved', $entry['decision']);
		self::assertSame('Mooi', $entry['note']);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $entry['decidedAt']);
	}//end testAnEntry()

	/**
	 * Entries are appended after the existing ones.
	 *
	 * @return void
	 */
	public function testEntriesAreAppended(): void {
		$approvals = (new PostApprovals())->appended(post: ['approvals' => [['userId' => 'bram']]], approval: ['userId' => 'anna']);

		self::assertSame([['userId' => 'bram'], ['userId' => 'anna']], $approvals);
	}//end testEntriesAreAppended()

	/**
	 * Missing or malformed approvals start a new list.
	 *
	 * @return void
	 */
	public function testMissingApprovalsStartANewList(): void {
		self::assertSame([['userId' => 'anna']], (new PostApprovals())->appended(post: [], approval: ['userId' => 'anna']));
		self::assertSame([['userId' => 'anna']], (new PostApprovals())->appended(post: ['approvals' => 'none'], approval: ['userId' => 'anna']));
	}//end testMissingApprovalsStartANewList()
}//end class
