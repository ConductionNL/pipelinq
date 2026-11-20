<?php

/**
 * Unit tests for GiftCardDebit.
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

use OCA\Pipelinq\Service\GiftCardDebit;
use PHPUnit\Framework\TestCase;

/**
 * GiftCardDebit works out what a payment takes off a gift card.
 */
class GiftCardDebitTest extends TestCase {

	/**
	 * A payment smaller than the balance is a partial redeem.
	 *
	 * @return void
	 */
	public function testASmallerPaymentIsAPartialRedeem(): void {
		$debit = GiftCardDebit::fromBalance(balance: 50.0, amount: 12.5);

		self::assertSame(12.5, $debit->applied);
		self::assertSame(0.0, $debit->change);
		self::assertSame(37.5, $debit->balanceAfter);
		self::assertSame('partial_redeem', $debit->type);
		self::assertFalse($debit->depleted);
	}//end testASmallerPaymentIsAPartialRedeem()

	/**
	 * Paying exactly the balance redeems the card.
	 *
	 * @return void
	 */
	public function testPayingTheBalanceRedeemsTheCard(): void {
		$debit = GiftCardDebit::fromBalance(balance: 25.0, amount: 25.0);

		self::assertSame(25.0, $debit->applied);
		self::assertSame(0.0, $debit->change);
		self::assertSame(0.0, $debit->balanceAfter);
		self::assertSame('redeem', $debit->type);
		self::assertTrue($debit->depleted);
	}//end testPayingTheBalanceRedeemsTheCard()

	/**
	 * A payment above the balance empties the card and leaves the rest to pay otherwise.
	 *
	 * @return void
	 */
	public function testALargerPaymentLeavesChange(): void {
		$debit = GiftCardDebit::fromBalance(balance: 20.0, amount: 30.0);

		self::assertSame(20.0, $debit->applied);
		self::assertSame(10.0, $debit->change);
		self::assertSame(0.0, $debit->balanceAfter);
		self::assertSame('partial_redeem', $debit->type);
		self::assertTrue($debit->depleted);
	}//end testALargerPaymentLeavesChange()

	/**
	 * The balance after is rounded to cents.
	 *
	 * @return void
	 */
	public function testTheBalanceAfterIsRoundedToCents(): void {
		$debit = GiftCardDebit::fromBalance(balance: 10.1, amount: 0.3);

		self::assertSame(9.8, $debit->balanceAfter);
		self::assertFalse($debit->depleted);
	}//end testTheBalanceAfterIsRoundedToCents()

	/**
	 * An empty card takes nothing and stays depleted.
	 *
	 * @return void
	 */
	public function testAnEmptyCardTakesNothing(): void {
		$debit = GiftCardDebit::fromBalance(balance: 0.0, amount: 5.0);

		self::assertSame(0.0, $debit->applied);
		self::assertSame(5.0, $debit->change);
		self::assertSame(0.0, $debit->balanceAfter);
		self::assertTrue($debit->depleted);
		self::assertSame('partial_redeem', $debit->type);
	}//end testAnEmptyCardTakesNothing()
}//end class
