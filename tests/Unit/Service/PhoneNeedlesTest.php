<?php

/**
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\PhoneNeedles;
use PHPUnit\Framework\TestCase;

/**
 * The fragments a number is matched on, shared by the screen pop and the ticket search.
 *
 * @covers \OCA\Pipelinq\Service\PhoneNeedles
 *
 * @spec openspec/changes/contact-moments-phone-lookup/tasks.md#task-1.1
 */
class PhoneNeedlesTest extends TestCase {

	/**
	 * A Dutch mobile gives its E.164 value, its digits and its last nine and eight digits.
	 *
	 * @return void
	 */
	public function testAMobileNumberGivesFourFragments(): void {
		$this->assertSame(
			['+31612345678', '31612345678', '612345678', '12345678'],
			PhoneNeedles::for('+31612345678')
		);
	}//end testAMobileNumberGivesFourFragments()

	/**
	 * A short number gives no tail it does not have, and no duplicates.
	 *
	 * @return void
	 */
	public function testAShortNumberGivesNoTail(): void {
		$this->assertSame(['+3114', '3114'], PhoneNeedles::for('+3114'));
		$this->assertSame(['12345678'], PhoneNeedles::for('12345678'));
	}//end testAShortNumberGivesNoTail()

	/**
	 * An empty number gives nothing to match on.
	 *
	 * @return void
	 */
	public function testAnEmptyNumberGivesNothing(): void {
		$this->assertSame([], PhoneNeedles::for(''));
	}//end testAnEmptyNumberGivesNothing()

	/**
	 * The last nine digits are the same however the number was typed.
	 *
	 * @return void
	 */
	public function testTheTailIgnoresHowTheNumberWasTyped(): void {
		foreach (['+31612345678', '06-12345678', '+31 6 1234 5678', '0031612345678', '(06) 12 34 56 78'] as $typed) {
			$this->assertSame('612345678', PhoneNeedles::tail($typed), $typed);
		}

		$this->assertNull(PhoneNeedles::tail('12345678'));
	}//end testTheTailIgnoresHowTheNumberWasTyped()
}//end class
