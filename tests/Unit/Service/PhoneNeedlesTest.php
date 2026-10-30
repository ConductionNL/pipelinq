<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/contact-moments-phone-lookup/specs/contact-history-by-phone/spec.md#requirement-a-ticket-is-found-by-phone-number-however-it-was-typed-req-chp-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\PhoneNeedles;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Service\PhoneNeedles
 */
class PhoneNeedlesTest extends TestCase {
	/**
	 * A Dutch mobile number yields its E.164 value, its digits and its two tails.
	 *
	 * @return void
	 */
	public function testANumberYieldsItsFourFragments(): void {
		$this->assertSame(['+31612345678', '31612345678', '612345678', '12345678'], PhoneNeedles::for('+31612345678'));
	}//end testANumberYieldsItsFourFragments()

	/**
	 * A short number yields no tail longer than itself, and no duplicates.
	 *
	 * @return void
	 */
	public function testAShortNumberYieldsNoDuplicates(): void {
		$this->assertSame(['+3112345', '3112345'], PhoneNeedles::for('+3112345'));
		$this->assertSame([], PhoneNeedles::for(''));
	}//end testAShortNumberYieldsNoDuplicates()
}//end class
