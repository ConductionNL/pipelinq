<?php

/**
 * Characterisation test for the paged register reads of ShillinqInvoiceReader
 * and SearchQueryReportService.
 *
 * Pins every findAll call (filters, limit, offset, access flags), where the
 * paging stops (a short page, the row cap, a failed read, a non-list answer),
 * the warning a failed read writes, and the rows that come back.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-attribution-closes-on-a-paid-invoice-or-on-a-won-lead-and-the-report-says-which
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\SearchConsole\SearchQueryReportService;
use OCA\Pipelinq\Service\ShillinqInvoiceReader;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Paging of both register readers.
 */
class RegisterPagingCharacterisationTest extends TestCase {
	/**
	 * Warnings logged.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $warnings = [];

	/**
	 * The fake object service.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * The invoice reader pages by 100 up to 500 rows, with or without the caller's rights.
	 *
	 * @return void
	 */
	public function testTheInvoiceReaderPages(): void {
		$paid = static fn (int $i): array => ['uuid' => 'inv-' . $i, 'lifecycleState' => 'paid', 'grossAmount' => $i];
		$full = static fn (int $from): array => array_map($paid, range($from, ($from + 99)));

		$reader = $this->invoiceReader(pages: [$full(0), array_map($paid, range(100, 129))]);
		$invoices = $reader->paidInvoicesFor(customerRef: ' cust-1 ', from: '', to: '');
		self::assertCount(130, $invoices);
		$filters = ['register' => 'shillinq', 'schema' => 'ARInvoice', 'customerId' => 'cust-1', 'lifecycleState' => 'paid'];
		self::assertSame(
			[
				[['filters' => $filters, 'limit' => 100, 'offset' => 0], false, false],
				[['filters' => $filters, 'limit' => 100, 'offset' => 100], false, false],
			],
			$this->objects->calls
		);

		$reader = $this->invoiceReader(pages: [$full(0), $full(100), $full(200), $full(300), $full(400), $full(500)])->withCallerRights();
		$all = $reader->invoicesFor(customerRef: 'cust-2');
		self::assertSame([0, 100, 200, 300, 400], array_map(static fn (array $c): int => $c[0]['offset'], $this->objects->calls));
		self::assertSame(['register' => 'shillinq', 'schema' => 'ARInvoice', 'customerId' => 'cust-2'], $this->objects->calls[0][0]['filters']);
		self::assertSame([true, true], array_slice($this->objects->calls[4], 1));
		self::assertCount(500, $all);
		self::assertSame([], $this->warnings);

		$reader = $this->invoiceReader(pages: [$full(0), new RuntimeException('db gone'), $full(200)]);
		self::assertCount(100, $reader->paidInvoicesFor(customerRef: 'cust-3', from: '', to: ''));
		self::assertCount(2, $this->objects->calls);
		self::assertSame([['ShillinqInvoiceReader: reading AR invoices failed', ['customer' => 'cust-3', 'exception' => 'db gone']]], $this->warnings);

		$reader = $this->invoiceReader(pages: ['not a list']);
		self::assertSame([], $reader->invoicesFor(customerRef: 'cust-4'));
		self::assertCount(1, $this->objects->calls);
		self::assertSame([], $this->warnings);
	}//end testTheInvoiceReaderPages()

	/**
	 * The search report pages by 500 without access flags and logs a failed read.
	 *
	 * @return void
	 */
	public function testTheSearchReportPages(): void {
		$row = static fn (int $i): array => ['date' => '2026-09-01', 'query' => 'q' . ($i % 3), 'page' => '/p', 'clicks' => 1, 'impressions' => 2, 'position' => 1.0];
		$service = $this->searchReport(pages: [array_map($row, range(1, 500)), array_map($row, range(1, 7))]);
		$rows = $service->rows(from: '2026-09-01', to: '2026-09-02', property: ' sc-domain:x ');
		self::assertCount(3, $rows);
		self::assertSame(
			[
				[['filters' => ['register' => 'pipelinq', 'schema' => 'searchQueryDaily', 'date' => ['gte' => '2026-09-01', 'lt' => '2026-09-03'], 'property' => 'sc-domain:x'], 'limit' => 500, 'offset' => 0], false, false],
				[['filters' => ['register' => 'pipelinq', 'schema' => 'searchQueryDaily', 'date' => ['gte' => '2026-09-01', 'lt' => '2026-09-03'], 'property' => 'sc-domain:x'], 'limit' => 500, 'offset' => 500], false, false],
			],
			$this->objects->calls
		);

		$service = $this->searchReport(pages: [array_map($row, range(1, 500)), new RuntimeException('timeout')]);
		self::assertCount(3, $service->rows(from: '2026-09-01', to: '2026-09-01'));
		self::assertCount(2, $this->objects->calls);
		self::assertSame([['SearchQueryReportService: findAll failed', ['exception' => 'timeout']]], $this->warnings);
	}//end testTheSearchReportPages()

	/**
	 * A fake object service answering pages from a queue.
	 *
	 * @param array<int, mixed> $pages The answers, a Throwable to throw.
	 *
	 * @return object
	 */
	private function objectService(array $pages): object {
		$this->warnings = [];
		$this->objects = new class ($pages) {
			/**
			 * Calls seen: config, rbac, multitenancy.
			 *
			 * @var array<int, array<int, mixed>>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param array<int, mixed> $pages Answers.
			 */
			public function __construct(private array $pages) {
			}

			/**
			 * Answer the next page.
			 *
			 * @param array<string, mixed> $config        The query.
			 * @param bool                 $_rbac         RBAC flag.
			 * @param bool                 $_multitenancy Multitenancy flag.
			 *
			 * @return mixed
			 */
			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): mixed {
				$this->calls[] = [$config, $_rbac, $_multitenancy];
				$page = array_shift($this->pages) ?? [];
				if ($page instanceof \Throwable) {
					throw $page;
				}

				return $page;
			}
		};

		return $this->objects;
	}//end objectService()

	/**
	 * A logger that records warnings.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);
		return $logger;
	}//end logger()

	/**
	 * The invoice reader over the fake, shillinq present.
	 *
	 * @param array<int, mixed> $pages The answers.
	 *
	 * @return ShillinqInvoiceReader
	 */
	private function invoiceReader(array $pages): ShillinqInvoiceReader {
		$objects = $this->objectService(pages: $pages);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);

		return new class ($container, $this->logger()) extends ShillinqInvoiceReader {
			/**
			 * Shillinq counts as installed.
			 *
			 * @return bool
			 */
			protected function probe(): bool {
				return true;
			}
		};
	}//end invoiceReader()

	/**
	 * The search report over the fake.
	 *
	 * @param array<int, mixed> $pages The answers.
	 *
	 * @return SearchQueryReportService
	 */
	private function searchReport(array $pages): SearchQueryReportService {
		$objects = $this->objectService(pages: $pages);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1788516000);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);

		return new SearchQueryReportService($container, $appConfig, $time, $this->logger());
	}//end searchReport()
}//end class
