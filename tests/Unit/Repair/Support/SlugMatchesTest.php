<?php

/**
 * Behavioural tests for the SlugMatches value object.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair\Support;

use OCA\Pipelinq\Repair\Support\SlugMatches;
use PHPUnit\Framework\TestCase;

/**
 * A lookup is unreadable when either side failed; the old id is the first old match.
 *
 * @covers \OCA\Pipelinq\Repair\Support\SlugMatches
 */
class SlugMatchesTest extends TestCase {

	/**
	 * @return void
	 */
	public function testBothSidesReadIsReadableAndYieldsFirstOldId(): void {
		$matches = new SlugMatches(old: [42, 43], new: []);

		$this->assertFalse($matches->isUnreadable());
		$this->assertSame(42, $matches->oldId());
	}//end testBothSidesReadIsReadableAndYieldsFirstOldId()

	/**
	 * @return void
	 */
	public function testAFailedSideIsUnreadable(): void {
		$this->assertTrue((new SlugMatches(old: null, new: [1]))->isUnreadable());
		$this->assertTrue((new SlugMatches(old: [1], new: null))->isUnreadable());
		$this->assertTrue((new SlugMatches(old: null, new: null))->isUnreadable());
	}//end testAFailedSideIsUnreadable()

	/**
	 * @return void
	 */
	public function testNoOldMatchHasNoOldId(): void {
		$this->assertNull((new SlugMatches(old: [], new: [7]))->oldId());
		$this->assertNull((new SlugMatches(old: null, new: [7]))->oldId());
	}//end testNoOldMatchHasNoOldId()
}//end class
