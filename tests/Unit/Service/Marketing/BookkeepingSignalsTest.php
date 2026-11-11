<?php

/**
 * BookkeepingSignals::forCustomer: every way the bookkeeping signals end.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\Marketing\BookkeepingSignals;
use OCA\Pipelinq\Service\ShillinqInvoiceReader;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * No bookkeeping, no customer or no invoices is every signal unknown.
 */
class BookkeepingSignalsTest extends TestCase {

	/**
	 * Every way the signals end: a blank customer and no bookkeeping read
	 * nothing and know nothing; a customer without invoices knows nothing;
	 * a customer with invoices gets every signal, in the catalogue's order.
	 *
	 * @return void
	 */
	public function testEveryWayTheSignalsEnd(): void {
		$blank = ['shillinqRecognisedRevenue' => null, 'shillinqValueTier' => null, 'shillinqMonthsSinceLastInvoice' => null, 'shillinqPurchasedProducts' => null, 'shillinqPurchasedServices' => null, 'shillinqDunningState' => null];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnArgument(2);
		$appConfig->method('getValueString')->willReturnArgument(2);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1767225600);

		$off = $this->createMock(ShillinqInvoiceReader::class);
		$off->method('isAvailable')->willReturn(false);
		$off->expects($this->never())->method('invoicesFor');
		$this->assertSame($blank, (new BookkeepingSignals($off, $appConfig, $time))->forCustomer(customerRef: 'c-1', catalogue: []));

		$on = $this->createMock(ShillinqInvoiceReader::class);
		$on->method('isAvailable')->willReturn(true);
		$on->method('invoicesFor')->willReturnCallback(static fn (string $customerRef): array => ($customerRef === 'c-1' ? [['status' => 'paid', 'invoiceDate' => '2025-12-01', 'amount' => 100]] : []));
		$on->method('paidInvoicesFor')->willReturn([['amount' => 30000.5]]);
		$signals = new BookkeepingSignals($on, $appConfig, $time);
		$this->assertSame($blank, $signals->forCustomer(customerRef: ' ', catalogue: []));
		$this->assertSame($blank, $signals->forCustomer(customerRef: 'c-9', catalogue: []));

		$known = $signals->forCustomer(customerRef: ' c-1 ', catalogue: []);
		$this->assertSame(
			['shillinqRecognisedRevenue' => 30000.5, 'shillinqValueTier' => 'top', 'shillinqMonthsSinceLastInvoice' => 1, 'shillinqPurchasedProducts' => [], 'shillinqPurchasedServices' => [], 'shillinqDunningState' => 'current'],
			$known
		);
	}//end testEveryWayTheSignalsEnd()
}//end class
