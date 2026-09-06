<?php

/**
 * Unit tests for ShillinqInvoiceReader.
 *
 * Covers:
 * - shillinq absent reads nothing and answers unavailable
 * - the query names shillinq's register and schema, with the access flags off
 * - only invoices in lifecycleState paid, inside the window, are returned
 * - a row whose state the filter failed to apply is dropped in PHP as well
 *
 * Shillinq is never installed here: the presence probe is a protected
 * method an anonymous subclass answers for, and the object service is a
 * hand-written fake resolved from a mocked container.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-attribution-closes-on-a-paid-invoice-or-on-a-won-lead-and-the-report-says-which
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ShillinqInvoiceReader;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ShillinqInvoiceReader.
 *
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-attribution-closes-on-a-paid-invoice-or-on-a-won-lead-and-the-report-says-which
 */
class ShillinqInvoiceReaderTest extends TestCase {

	/**
	 * The fake object service, recording every call.
	 *
	 * @var object
	 */
	private object $objectService;

	/**
	 * Build a reader over a fake object service.
	 *
	 * @param bool $installed What the presence probe answers.
	 * @param array<int, array<string, mixed>> $rows What findAll answers.
	 *
	 * @return ShillinqInvoiceReader
	 */
	private function build(bool $installed = true, array $rows = []): ShillinqInvoiceReader {
		$this->objectService = new class ($rows) {

			/**
			 * Every call findAll saw.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $calls = [];

			/**
			 * @param array<int, array<string, mixed>> $rows The rows to answer with.
			 */
			public function __construct(private array $rows) {
			}//end __construct()

			/**
			 * @param array<string, mixed> $config The query.
			 * @param bool $_rbac Access flag.
			 * @param bool $_multitenancy Access flag.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->calls[] = ['config' => $config, '_rbac' => $_rbac, '_multitenancy' => $_multitenancy];
				return $this->rows;
			}//end findAll()
		};

		$service = $this->objectService;
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($service): object {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $service;
				}

				throw new \RuntimeException('not registered: ' . $id);
			}
		);

		return new class ($installed, $container, $this->createMock(LoggerInterface::class)) extends ShillinqInvoiceReader {

			/**
			 * @param bool $installed What the probe answers.
			 * @param mixed ...$args The real constructor arguments.
			 */
			public function __construct(private bool $installed, ...$args) {
				parent::__construct(...$args);
			}//end __construct()

			/**
			 * @return bool
			 */
			protected function probe(): bool {
				return $this->installed;
			}//end probe()
		};
	}//end build()

	/**
	 * @return void
	 */
	public function testShillinqAbsentReadsNothing(): void {
		$reader = $this->build(installed: false, rows: [['lifecycleState' => 'paid', 'uuid' => 'inv-1', 'grossAmount' => 100]]);

		$this->assertFalse($reader->isAvailable());
		$this->assertSame([], $reader->paidInvoicesFor(customerRef: 'cust-1', from: '2026-01-01', to: '2026-12-31'));
		$this->assertSame([], $this->objectService->calls);
	}//end testShillinqAbsentReadsNothing()

	/**
	 * @return void
	 */
	public function testTheQueryNamesShillinqsRegisterWithTheAccessFlagsOff(): void {
		$this->build()->paidInvoicesFor(customerRef: 'cust-1', from: '2026-01-01', to: '2026-12-31');

		$this->assertCount(1, $this->objectService->calls);
		$call = $this->objectService->calls[0];
		$this->assertSame('shillinq', $call['config']['filters']['register']);
		$this->assertSame('ARInvoice', $call['config']['filters']['schema']);
		$this->assertSame('cust-1', $call['config']['filters']['customerId']);
		$this->assertSame('paid', $call['config']['filters']['lifecycleState']);
		$this->assertFalse($call['_rbac']);
		$this->assertFalse($call['_multitenancy']);
	}//end testTheQueryNamesShillinqsRegisterWithTheAccessFlagsOff()

	/**
	 * @return void
	 */
	public function testReadsOnlyPaidInvoicesInTheWindow(): void {
		$reader = $this->build(
			rows: [
				['uuid' => 'inv-1', 'lifecycleState' => 'paid', 'grossAmount' => 4840, 'currency' => 'EUR', 'invoiceDate' => '2026-11-02', 'invoiceNumber' => '2026-014'],
				['uuid' => 'inv-2', 'lifecycleState' => 'paid', 'grossAmount' => 1000, 'invoiceDate' => '2025-01-01'],
				['uuid' => 'inv-3', 'lifecycleState' => 'issued', 'grossAmount' => 2000, 'invoiceDate' => '2026-11-03'],
			]
		);

		$invoices = $reader->paidInvoicesFor(customerRef: 'cust-1', from: '2026-10-01', to: '2026-11-30');

		$this->assertSame(['inv-1'], array_column($invoices, 'id'));
		$this->assertSame(4840.0, $invoices[0]['amount']);
		$this->assertSame('2026-014', $invoices[0]['invoiceNumber']);
	}//end testReadsOnlyPaidInvoicesInTheWindow()

	/**
	 * @return void
	 */
	public function testAnInvoiceWithoutADateStillCounts(): void {
		$reader = $this->build(rows: [['uuid' => 'inv-4', 'lifecycleState' => 'paid', 'grossAmount' => 250]]);

		$invoices = $reader->paidInvoicesFor(customerRef: 'cust-1', from: '2026-10-01', to: '2026-11-30');

		$this->assertSame(['inv-4'], array_column($invoices, 'id'));
	}//end testAnInvoiceWithoutADateStillCounts()

	/**
	 * @return void
	 */
	public function testAnEmptyCustomerReferenceReadsNothing(): void {
		$this->build()->paidInvoicesFor(customerRef: '  ', from: '', to: '');

		$this->assertSame([], $this->objectService->calls);
	}//end testAnEmptyCustomerReferenceReadsNothing()
}//end class
