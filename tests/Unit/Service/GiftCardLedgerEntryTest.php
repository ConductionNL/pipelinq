<?php

/**
 * Unit tests for GiftCardLedgerEntry.
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

use OCA\Pipelinq\Service\GiftCardLedgerEntry;
use PHPUnit\Framework\TestCase;

/**
 * GiftCardLedgerEntry carries its values unchanged.
 */
class GiftCardLedgerEntryTest extends TestCase {

	/**
	 * Every constructor value is readable as given.
	 *
	 * @return void
	 */
	public function testKeepsTheValuesItIsGiven(): void {
		$value = new GiftCardLedgerEntry(giftCardId: 'card-1', type: 'redeem', amount: 12.5, balanceAfter: 37.5);

		self::assertSame('card-1', $value->giftCardId);
		self::assertSame('redeem', $value->type);
		self::assertSame(12.5, $value->amount);
		self::assertSame(37.5, $value->balanceAfter);
	}//end testKeepsTheValuesItIsGiven()
}//end class
