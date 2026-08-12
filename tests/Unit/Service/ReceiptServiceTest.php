<?php

/**
 * Unit tests for ReceiptService.
 *
 * Covers the pure receipt rendering core: the default-template / layout-width
 * merge, the BTW lines derived from the persisted invoiceBreakdown (tax is
 * never recomputed here), the ESC/POS byte-stream shape (init + cut framing),
 * and the >= EUR 100 legal-invoice branch (heading, BTW breakdown and Dutch
 * compliance footer). These are the contracts a thermal printer and the email
 * body depend on; live spooling / SMTP are environment-gated and exercised
 * elsewhere.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ReceiptService;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ReceiptService.
 */
class ReceiptServiceTest extends TestCase {
	/**
	 * The service under test.
	 *
	 * @var ReceiptService
	 */
	private ReceiptService $service;

	/**
	 * Set up the test with an identity-translating l10n and an app config that
	 * returns blank company details (so the header reflects defaults only).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') {
				if ($key === 'receipt_company_name') {
					return 'Test Winkel B.V.';
				}

				if ($key === 'receipt_company_vat') {
					return 'NL860784241B01';
				}

				if ($key === 'receipt_company_kvk') {
					return '76741850';
				}

				return $default;
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => $text
		);

		$this->service = new ReceiptService($appConfig, $l10n);
	}//end setUp()

	/**
	 * A small transaction (< EUR 100) renders a simple receipt: RECEIPT heading,
	 * an aggregate VAT line, no per-rate breakdown and no compliance footer.
	 *
	 * @return void
	 */
	public function testRenderTextSimpleReceiptBelowThreshold(): void {
		$transaction = $this->sampleTransaction(total: 45.50, totalTax: 7.89);
		$text = $this->service->renderText(transaction: $transaction);

		$this->assertStringContainsString('RECEIPT', $text);
		$this->assertStringNotContainsString('INVOICE', $text);
		$this->assertStringContainsString('Test Winkel B.V.', $text);
		$this->assertStringContainsString('Thank you for your purchase', $text);
		// Simple receipts must not carry the legal compliance footer.
		$this->assertStringNotContainsString('NL860784241B01', $text);
	}//end testRenderTextSimpleReceiptBelowThreshold()

	/**
	 * A transaction >= EUR 100 auto-renders the legal invoice variant: INVOICE
	 * heading, per-rate BTW lines taken from invoiceBreakdown, and the Dutch
	 * compliance footer (VAT id + KvK). This holds even when the template is a
	 * plain (isInvoiceMode=false) one.
	 *
	 * @return void
	 */
	public function testRenderTextLegalInvoiceAtOrAboveThreshold(): void {
		$transaction = $this->sampleTransaction(total: 125.00, totalTax: 21.84);
		// A deliberately simple template must not downgrade a high-value sale.
		$text = $this->service->renderText(
			transaction: $transaction,
			template: ['isInvoiceMode' => false, 'layoutWidth' => 0]
		);

		$this->assertStringContainsString('INVOICE', $text);
		$this->assertStringNotContainsString('RECEIPT', $text);
		// Per-rate BTW description from the persisted invoiceBreakdown.
		$this->assertStringContainsString('Standaardtarief (21%)', $text);
		// Dutch compliance footer carries the VAT id and Chamber of Commerce no.
		$this->assertStringContainsString('NL860784241B01', $text);
		$this->assertStringContainsString('76741850', $text);
	}//end testRenderTextLegalInvoiceAtOrAboveThreshold()

	/**
	 * The BTW lines come from the persisted invoiceBreakdown verbatim — the
	 * service does not recompute tax. Two rates produce two distinct lines.
	 *
	 * @return void
	 */
	public function testInvoiceBtwLinesComeFromPersistedBreakdown(): void {
		$transaction = $this->sampleTransaction(total: 218.00, totalTax: 30.00);
		$transaction['invoiceBreakdown'] = [
			['rate' => 9, 'base' => 100.00, 'tax' => 9.00, 'description' => 'Verlaagd tarief (9%)'],
			['rate' => 21, 'base' => 100.00, 'tax' => 21.00, 'description' => 'Standaardtarief (21%)'],
		];

		$text = $this->service->renderText(transaction: $transaction);

		$this->assertStringContainsString('Verlaagd tarief (9%)', $text);
		$this->assertStringContainsString('Standaardtarief (21%)', $text);
		// The persisted tax figures are surfaced (formatted nl-style with comma).
		$this->assertStringContainsString('9,00', $text);
		$this->assertStringContainsString('21,00', $text);
	}//end testInvoiceBtwLinesComeFromPersistedBreakdown()

	/**
	 * The ESC/POS stream is framed with the init (ESC @) and full-cut (GS V 0)
	 * commands and contains the receipt body — the shape a thermal printer
	 * consumes.
	 *
	 * @return void
	 */
	public function testRenderEscPosFramingAndCut(): void {
		$transaction = $this->sampleTransaction(total: 45.50, totalTax: 7.89);
		$bytes = $this->service->renderEscPos(transaction: $transaction);

		// Begins with the printer reset/init command (ESC @).
		$this->assertStringStartsWith("\x1B\x40", $bytes);
		// Ends with a full paper cut (GS V 0).
		$this->assertStringEndsWith("\x1D\x56\x00", $bytes);
		// Contains the bold + centre alignment control codes for the heading.
		$this->assertStringContainsString("\x1B\x45\x01", $bytes);
		$this->assertStringContainsString("\x1B\x61\x01", $bytes);
	}//end testRenderEscPosFramingAndCut()

	/**
	 * The invoice-mode decision is driven by the persisted total only; a client
	 * cannot influence it via the template.
	 *
	 * @return void
	 */
	public function testIsInvoiceTransactionThreshold(): void {
		$this->assertFalse($this->service->isInvoiceTransaction(['total' => 99.99]));
		$this->assertTrue($this->service->isInvoiceTransaction(['total' => 100.00]));
		$this->assertTrue($this->service->isInvoiceTransaction(['total' => 250.00]));
		$this->assertSame(100.0, $this->service->invoiceThreshold());
	}//end testIsInvoiceTransactionThreshold()

	/**
	 * An invoice number present on the transaction is surfaced on the invoice;
	 * absent it simply does not appear (no forged placeholder).
	 *
	 * @return void
	 */
	public function testInvoiceNumberRenderedWhenPresent(): void {
		$transaction = $this->sampleTransaction(total: 125.00, totalTax: 21.84);
		$transaction['invoiceNumber'] = '2026-000042';

		$text = $this->service->renderText(transaction: $transaction);

		$this->assertStringContainsString('2026-000042', $text);
	}//end testInvoiceNumberRenderedWhenPresent()

	/**
	 * HTML rendering escapes content and wraps it in a <pre> block, so markup
	 * injected through transaction fields cannot break out into the email body.
	 *
	 * @return void
	 */
	public function testRenderHtmlEscapesContent(): void {
		$transaction = $this->sampleTransaction(total: 45.50, totalTax: 7.89);
		$transaction['reference'] = '<script>alert(1)</script>';

		$html = $this->service->renderHtml(transaction: $transaction);

		$this->assertStringContainsString('<pre', $html);
		$this->assertStringNotContainsString('<script>alert(1)</script>', $html);
		$this->assertStringContainsString('&lt;script&gt;', $html);
	}//end testRenderHtmlEscapesContent()

	/**
	 * Build a minimal persisted transaction with one line and a 21% breakdown.
	 *
	 * @param float $total The grand total.
	 * @param float $totalTax The aggregate tax.
	 *
	 * @return array<string, mixed> The transaction.
	 */
	private function sampleTransaction(float $total, float $totalTax): array {
		return [
			'id' => 'txn-test-0001',
			'reference' => 'TXN-2026-0001',
			'status' => 'settled',
			'confirmedAt' => '2026-05-21T14:30:22+00:00',
			'subtotal' => ($total - $totalTax),
			'discountTotal' => 0.0,
			'totalTax' => $totalTax,
			'total' => $total,
			'invoiceBreakdown' => [
				['rate' => 21, 'base' => ($total - $totalTax), 'tax' => $totalTax, 'description' => 'Standaardtarief (21%)'],
			],
			'lines' => [
				['description' => 'Koffie', 'quantity' => 2, 'lineTotal' => $total],
			],
		];
	}//end sampleTransaction()
}//end class
