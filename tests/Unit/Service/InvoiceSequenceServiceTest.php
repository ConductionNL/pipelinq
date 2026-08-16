<?php

/**
 * Unit tests for InvoiceSequenceService.
 *
 * Covers the server-authoritative, non-forgeable legal invoice numbering: the
 * formatted output shape, the monotonic increment across calls, the
 * compare-and-set win/loss behaviour that keeps concurrent allocations unique,
 * and the year reset. The client never supplies a number, so these tests assert
 * the allocator alone determines it. The DB-touching compare-and-set / seed
 * steps are exercised through an in-memory test subclass so the allocation
 * logic (year reset, increment, retry) runs unmocked.
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

use OCA\Pipelinq\Service\InvoiceSequenceService;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * In-memory test double: replaces only the two DB-touching steps with an array
 * store, leaving the public allocation logic of the real service in place. The
 * compare-and-set honours an injectable "lost race" count so concurrency can be
 * simulated deterministically.
 */
final class InMemoryInvoiceSequenceService extends InvoiceSequenceService {
	/**
	 * The simulated oc_appconfig counter store.
	 *
	 * @var array<string, int>
	 */
	public array $store = [];

	/**
	 * Number of compare-and-set calls that should report a lost race before one
	 * is allowed to win.
	 *
	 * @var int
	 */
	public int $loseRaces = 0;

	/**
	 * Compare-and-set against the in-memory store, honouring simulated races.
	 *
	 * @param int $expected The value we read.
	 * @param int $next The value to set.
	 *
	 * @return int 1 when the update applied, else 0.
	 */
	protected function casCounter(int $expected, int $next): int {
		if ($this->loseRaces > 0) {
			$this->loseRaces--;
			return 0;
		}

		if (($this->store['receipt_invoice_counter'] ?? 0) !== $expected) {
			return 0;
		}

		$this->store['receipt_invoice_counter'] = $next;
		return 1;
	}

	/**
	 * Seed the in-memory counter at 1 if absent.
	 *
	 * @return int 1 when this call set the value to 1, else 0.
	 */
	protected function seedCounter(): int {
		if (($this->store['receipt_invoice_counter'] ?? 0) !== 0) {
			return 0;
		}

		$this->store['receipt_invoice_counter'] = 1;
		return 1;
	}
}

/**
 * Tests for InvoiceSequenceService.
 */
class InvoiceSequenceServiceTest extends TestCase {
	/**
	 * The in-memory service under test.
	 *
	 * @var InMemoryInvoiceSequenceService
	 */
	private InMemoryInvoiceSequenceService $service;

	/**
	 * Build the in-memory service. getValueInt / setValueInt read and write the
	 * subclass's array store so the year-tracking and reset logic runs for real.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$service = new InMemoryInvoiceSequenceService($this->createMock(IDBConnection::class),
			$appConfig,
			$this->createMock(LoggerInterface::class)
		);

		$appConfig->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default = 0): int => ($service->store[$key] ?? $default)
		);
		$appConfig->method('setValueInt')->willReturnCallback(
			static function (string $app, string $key, int $value) use ($service): bool {
				$service->store[$key] = $value;
				return true;
			}
		);

		$this->service = $service;
	}//end setUp()

	/**
	 * The first allocation of the year yields ...-000001, formatted YYYY-NNNNNN.
	 *
	 * @return void
	 */
	public function testFirstNumberIsFormattedAndOne(): void {
		$year = (int)date('Y');
		$number = $this->service->next();

		$this->assertSame(sprintf('%04d-%06d', $year, 1), $number);
	}//end testFirstNumberIsFormattedAndOne()

	/**
	 * Consecutive allocations increase monotonically and never repeat.
	 *
	 * @return void
	 */
	public function testNumbersAreMonotonicAndUnique(): void {
		$numbers = [];
		for ($i = 0; $i < 5; $i++) {
			$numbers[] = $this->service->next();
		}

		$this->assertSame($numbers, array_values(array_unique($numbers)));
		$this->assertStringEndsWith('-000001', $numbers[0]);
		$this->assertStringEndsWith('-000005', $numbers[4]);
	}//end testNumbersAreMonotonicAndUnique()

	/**
	 * When the stored counter belongs to a previous year the sequence resets to
	 * 1 for the current year.
	 *
	 * @return void
	 */
	public function testCounterResetsForNewYear(): void {
		$year = (int)date('Y');
		// Simulate a counter left over from last year.
		$this->service->store['receipt_invoice_counter'] = 873;
		$this->service->store['receipt_invoice_counter_year'] = ($year - 1);

		$number = $this->service->next();

		$this->assertSame(sprintf('%04d-%06d', $year, 1), $number);
	}//end testCounterResetsForNewYear()

	/**
	 * A lost compare-and-set race (0 affected rows, then a win) still yields a
	 * unique number via the retry loop — concurrent allocators never collide.
	 *
	 * @return void
	 */
	public function testRetriesOnLostCasRace(): void {
		$year = (int)date('Y');
		// Seed an existing counter so the path goes through casCounter (not seed).
		$this->service->store['receipt_invoice_counter'] = 10;
		$this->service->store['receipt_invoice_counter_year'] = $year;
		// The first CAS attempt loses the race; the retry wins.
		$this->service->loseRaces = 1;

		$number = $this->service->next();

		$this->assertSame(sprintf('%04d-%06d', $year, 11), $number);
	}//end testRetriesOnLostCasRace()
}//end class
