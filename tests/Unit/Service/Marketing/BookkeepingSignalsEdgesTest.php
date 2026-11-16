<?php

/**
 * Edge cases of BookkeepingSignals: undated, unreadable and future invoice
 * dates, and the configured value-tier thresholds.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-bookkeeping-supplies-six-segment-fields-and-pipelinq-stores-none-of-them
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\Marketing\BookkeepingSignals;
use OCA\Pipelinq\Service\ShillinqInvoiceReader;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the BookkeepingSignals branches the main test does not reach.
 *
 * @covers \OCA\Pipelinq\Service\Marketing\BookkeepingSignals
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-bookkeeping-supplies-six-segment-fields-and-pipelinq-stores-none-of-them
 */
class BookkeepingSignalsEdgesTest extends TestCase {

	/**
	 * Now: 2026-01-01T00:00:00Z.
	 */
	private const NOW = 1767225600;

	/**
	 * Configured app values by key.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * An invoice without a date gives no month count.
	 *
	 * @return void
	 */
	public function testAnUndatedInvoiceGivesNoMonthCount(): void {
		$signals = $this->signals(invoices: [['lifecycleState' => 'paid', 'amount' => 10]], paid: []);

		$this->assertNull($signals['shillinqMonthsSinceLastInvoice']);
		$this->assertSame('current', $signals['shillinqDunningState']);
		$this->assertSame('none', $signals['shillinqValueTier']);
	}//end testAnUndatedInvoiceGivesNoMonthCount()

	/**
	 * An unreadable invoice date gives no month count rather than a guess.
	 *
	 * @return void
	 */
	public function testAnUnreadableInvoiceDateGivesNoMonthCount(): void {
		$signals = $this->signals(invoices: [['lifecycleState' => 'paid', 'invoiceDate' => 'zz-not-a-date']], paid: []);

		$this->assertNull($signals['shillinqMonthsSinceLastInvoice']);
	}//end testAnUnreadableInvoiceDateGivesNoMonthCount()

	/**
	 * An invoice dated after today is zero months ago, never a negative count.
	 *
	 * @return void
	 */
	public function testAFutureInvoiceIsZeroMonthsAgo(): void {
		$signals = $this->signals(
			invoices: [['lifecycleState' => 'paid', 'invoiceDate' => '2025-06-01'], ['lifecycleState' => 'paid', 'invoiceDate' => '2026-03-01']],
			paid: []
		);

		$this->assertSame(0, $signals['shillinqMonthsSinceLastInvoice']);
	}//end testAFutureInvoiceIsZeroMonthsAgo()

	/**
	 * Numeric configured thresholds replace the defaults; a non-numeric one is ignored.
	 *
	 * @return void
	 */
	public function testConfiguredThresholdsDecideTheTier(): void {
		$this->config = ['marketing.value_tier_top' => ' 1000 ', 'marketing.value_tier_mid' => '100'];
		$this->assertSame('top', $this->signals(invoices: [['lifecycleState' => 'paid']], paid: [['amount' => 1000]])['shillinqValueTier']);
		$this->assertSame('mid', $this->signals(invoices: [['lifecycleState' => 'paid']], paid: [['amount' => 150]])['shillinqValueTier']);
		$this->assertSame('low', $this->signals(invoices: [['lifecycleState' => 'paid']], paid: [['amount' => 99.99]])['shillinqValueTier']);

		$this->config = ['marketing.value_tier_top' => 'lots', 'marketing.value_tier_mid' => ''];
		$this->assertSame('mid', $this->signals(invoices: [['lifecycleState' => 'paid']], paid: [['amount' => 5000]])['shillinqValueTier']);
		$this->assertSame('low', $this->signals(invoices: [['lifecycleState' => 'paid']], paid: [['amount' => 4999]])['shillinqValueTier']);
	}//end testConfiguredThresholdsDecideTheTier()

	/**
	 * The signals of customer `c-1` over these invoices and paid invoices.
	 *
	 * @param array<int, array<string, mixed>> $invoices The invoices past draft.
	 * @param array<int, array<string, mixed>> $paid     The paid invoices in the window.
	 *
	 * @return array<string, mixed>
	 */
	private function signals(array $invoices, array $paid): array {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnArgument(2);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		$reader = $this->createMock(ShillinqInvoiceReader::class);
		$reader->method('isAvailable')->willReturn(true);
		$reader->method('invoicesFor')->willReturn($invoices);
		$reader->method('paidInvoicesFor')->willReturn($paid);

		return (new BookkeepingSignals($reader, $appConfig, $time))->forCustomer(customerRef: 'c-1', catalogue: []);
	}//end signals()
}//end class
