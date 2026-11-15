<?php

/**
 * Unit tests for DepositSessionRequest.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\DepositSessionRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DepositSessionRequest.
 */
class DepositSessionRequestTest extends TestCase {

	/**
	 * The request keeps every value it is given.
	 *
	 * @return void
	 */
	public function testKeepsItsValues(): void {
		$request = new DepositSessionRequest('b-1', 2500, 'eur', 'Deposit', 'https://portal.example/return');

		$this->assertSame(expected: 'b-1', actual: $request->bookingId);
		$this->assertSame(expected: 2500, actual: $request->amountCents);
		$this->assertSame(expected: 'eur', actual: $request->currency);
		$this->assertSame(expected: 'Deposit', actual: $request->description);
		$this->assertSame(expected: 'https://portal.example/return', actual: $request->returnUrl);
	}//end testKeepsItsValues()
}//end class
