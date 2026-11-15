<?php

/**
 * Unit tests for GiftCardIssue.
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

use OCA\Pipelinq\Service\GiftCardIssue;
use PHPUnit\Framework\TestCase;

/**
 * GiftCardIssue carries its values unchanged.
 */
class GiftCardIssueTest extends TestCase {

	/**
	 * Every constructor value is readable as given.
	 *
	 * @return void
	 */
	public function testKeepsTheValuesItIsGiven(): void {
		$programmeId = 'prog-1';
		$initialBalance = 25.0;
		$expiryDays = 365;
		$issuedIn = 'Shop';
		$value = new GiftCardIssue(programmeId: $programmeId, initialBalance: $initialBalance, expiryDays: $expiryDays, issuedIn: $issuedIn);

		self::assertSame($programmeId, $value->programmeId);
		self::assertSame($initialBalance, $value->initialBalance);
		self::assertSame($expiryDays, $value->expiryDays);
		self::assertSame($issuedIn, $value->issuedIn);
	}//end testKeepsTheValuesItIsGiven()
}//end class
