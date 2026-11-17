<?php

/**
 * Unit tests for PointsCreditRun: what a point-of-sale credit records.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Loyalty
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Loyalty;

use OCA\Pipelinq\Service\Loyalty\PointsCreditRun;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The source document, the processor and the idempotency key of a credit run.
 */
#[CoversClass(PointsCreditRun::class)]
class PointsCreditRunTest extends TestCase {

	/**
	 * A run for one till transaction.
	 *
	 * @param array $transaction The point-of-sale transaction.
	 *
	 * @return PointsCreditRun
	 */
	private function creditRun(array $transaction): PointsCreditRun {
		return new PointsCreditRun(
			programmeId: 'prog-1',
			accountId: 'acc-9',
			transaction: $transaction,
			context: ['amount' => 42.5, 'channel' => 'store', 'ignored' => 'x'],
		);
	}//end run()

	/**
	 * The source document names the transaction, the amount and the channel, nothing else.
	 *
	 * @return void
	 */
	public function testTheSourceDocumentNamesTheTransaction(): void {
		$run = $this->creditRun(transaction: ['posTransactionId' => 'T-100', 'posTerminalId' => 'till-3']);

		self::assertSame(
			['transactionId' => 'T-100', 'amount' => 42.5, 'channel' => 'store'],
			$run->sourceDocument()
		);
		self::assertSame('till-3', $run->processedBy());
		self::assertSame('pos:T-100', $run->operationId());
	}//end testTheSourceDocumentNamesTheTransaction()

	/**
	 * Without a transaction id there is no idempotency key, and the system processed it.
	 *
	 * @return void
	 */
	public function testATransactionWithoutIdsFallsBack(): void {
		$run = $this->creditRun(transaction: []);

		self::assertNull($run->sourceDocument()['transactionId']);
		self::assertSame('system', $run->processedBy());
		self::assertSame('', $run->operationId());
	}//end testATransactionWithoutIdsFallsBack()

	/**
	 * An empty transaction id counts as none; a numeric one is stringified.
	 *
	 * @return void
	 */
	public function testIdsAreReadAsStrings(): void {
		self::assertSame('', $this->creditRun(transaction: ['posTransactionId' => ''])->operationId());
		self::assertSame('pos:77', $this->creditRun(transaction: ['posTransactionId' => 77])->operationId());
		self::assertSame('12', $this->creditRun(transaction: ['posTerminalId' => 12])->processedBy());
	}//end testIdsAreReadAsStrings()
}//end class
