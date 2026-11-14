<?php

/**
 * Unit tests for PspPayment and SettlementUpdate.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Payment
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Payment;

use OCA\Pipelinq\Service\Payment\PspPayment;
use OCA\Pipelinq\Service\Payment\SettlementUpdate;
use PHPUnit\Framework\TestCase;

/**
 * Pins the PSP payment description and when a status update settles or
 * refunds a transaction.
 *
 * @covers \OCA\Pipelinq\Service\Payment\PspPayment
 * @covers \OCA\Pipelinq\Service\Payment\SettlementUpdate
 */
class PaymentValuesTest extends TestCase {

	/**
	 * The description names the transaction reference, else a generic label.
	 *
	 * @return void
	 */
	public function testThePaymentDescriptionNamesTheReference(): void {
		$payment = new PspPayment(['reference' => 'POS-0042', 'id' => 't-1'], 1250);

		$this->assertSame('POS-0042', $payment->reference());
		$this->assertSame('t-1', $payment->transactionId());
		$this->assertSame('Pipelinq POS POS-0042', $payment->description());

		$anonymous = new PspPayment([], 100);
		$this->assertSame('', $anonymous->reference());
		$this->assertSame('', $anonymous->transactionId());
		$this->assertSame('Pipelinq POS transactie', $anonymous->description());
	}//end testThePaymentDescriptionNamesTheReference()

	/**
	 * Only a change into settled settles, and only a change into refunded refunds.
	 *
	 * @return void
	 */
	public function testOnlyAChangeOfStatusSettlesOrRefunds(): void {
		$this->assertTrue((new SettlementUpdate('settled', 'pending', 'e1'))->settlesNow());
		$this->assertFalse((new SettlementUpdate('settled', 'settled', 'e2'))->settlesNow());
		$this->assertFalse((new SettlementUpdate('pending', 'open', 'e3'))->settlesNow());

		$this->assertTrue((new SettlementUpdate('refunded', 'settled', 'e4'))->refundsNow());
		$this->assertFalse((new SettlementUpdate('refunded', 'refunded', 'e5'))->refundsNow());
		$this->assertFalse((new SettlementUpdate('settled', 'pending', 'e6'))->refundsNow());
	}//end testOnlyAChangeOfStatusSettlesOrRefunds()
}//end class
