<?php

/**
 * Pipelinq InvoiceSequenceService.
 *
 * Allocates gap-free, monotonically increasing legal invoice numbers for POS
 * receipts that render as formal invoices (transactions >= EUR 100). Numbers
 * are server-authoritative and non-forgeable: the client never supplies an
 * invoice number, and the counter lives in oc_appconfig behind a row-locking
 * database transaction so concurrent settlements can never receive the same
 * number.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/pos-receipt-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCP\IAppConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Race-safe legal invoice number allocator.
 *
 * @spec openspec/specs/pos-receipt-engine/spec.md
 */
class InvoiceSequenceService {
	/**
	 * App-config key holding the last allocated counter value for the year.
	 *
	 * @var string
	 */
	protected const COUNTER_KEY = 'receipt_invoice_counter';

	/**
	 * App-config key holding the year the counter belongs to.
	 *
	 * @var string
	 */
	protected const COUNTER_YEAR_KEY = 'receipt_invoice_counter_year';

	/**
	 * Maximum number of transaction retries on a serialization conflict.
	 *
	 * @var int
	 */
	private const MAX_RETRIES = 5;

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection.
	 * @param IAppConfig $appConfig The app config (counter storage).
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private IDBConnection $db,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Allocate the next legal invoice number, e.g. "2026-000042".
	 *
	 * Performed inside a DB transaction with a locked read of the counter row so
	 * two concurrent callers can never receive the same number. The counter
	 * resets at the start of each calendar year. The client cannot influence the
	 * value in any way (REQ-PRE-004 — invoice numbering not forgeable).
	 *
	 * @return string The allocated invoice number.
	 *
	 * @throws RuntimeException If a unique number cannot be allocated after retries.
	 *
	 * @spec openspec/specs/pos-receipt-engine/spec.md
	 */
	public function next(): string {
		$year = (int)(new DateTimeImmutable())->format('Y');
		$attempt = 0;

		while ($attempt < self::MAX_RETRIES) {
			$attempt++;
			try {
				$next = $this->compareAndIncrement(year: $year);
				if ($next > 0) {
					return $this->format(year: $year, number: $next);
				}
			} catch (\Throwable $e) {
				$this->logger->warning(
					'Pipelinq: invoice sequence allocation retry',
					['attempt' => $attempt, 'exception' => $e->getMessage()]
				);
			}//end try
		}//end while

		throw new RuntimeException('Could not allocate a unique invoice number.');
	}//end next()

	/**
	 * Write a claimed counter value as the number handed out.
	 *
	 * @param int $year The calendar year.
	 * @param int $number The claimed counter value.
	 *
	 * @return string The number, e.g. "2026-000042".
	 */
	protected function format(int $year, int $number): string {
		return sprintf('%04d-%06d', $year, $number);
	}//end format()

	/**
	 * Atomically claim the next counter value via a compare-and-set UPDATE.
	 *
	 * Reads the current value, then issues a conditional UPDATE that only
	 * succeeds if the value is still what we read (and the stored year matches).
	 * Because the affected-row count is 1 only for the single caller that won the
	 * race, two concurrent allocators can never both claim the same number — the
	 * loser sees 0 affected rows and is retried by next(). Portable across all
	 * Nextcloud-supported databases (no FOR UPDATE / RETURNING needed) including
	 * SQLite. The counter resets to 1 at the start of each calendar year.
	 *
	 * @param int $year The current calendar year.
	 *
	 * @return int The claimed counter value, or 0 when the race was lost (retry).
	 *
	 * @spec openspec/specs/pos-receipt-engine/spec.md
	 */
	private function compareAndIncrement(int $year): int {
		$storedYear = $this->appConfig->getValueInt(AppIdentity::APP_ID, static::COUNTER_YEAR_KEY, 0);

		// New year (or first ever use): reset the sequence so the first number
		// of the year becomes 1. A concurrent caller that already reset is
		// harmless: the compare-and-set below still lets only one caller win.
		if ($storedYear !== $year) {
			$this->appConfig->setValueInt(AppIdentity::APP_ID, static::COUNTER_YEAR_KEY, $year);
			$this->resetCounter();
		}

		// Read the counter from the database, never from IAppConfig: its cache
		// is filled once per process and does not see the compare-and-set
		// writes, so a second allocation in the same process would read the
		// old value and hand out the same number again.
		$current = $this->readCounter();
		if ($current === null) {
			return $this->seedCounter();
		}

		$next = ($current + 1);
		if ($this->casCounter(expected: $current, next: $next) === 1) {
			return $next;
		}

		return 0;
	}//end compareAndIncrement()

	/**
	 * The counter as stored in the database, or null when it has no row yet.
	 *
	 * @return int|null The stored counter value.
	 *
	 * @spec openspec/specs/pos-receipt-engine/spec.md
	 */
	protected function readCounter(): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('configvalue')
			->from('appconfig')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter(AppIdentity::APP_ID)))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter(static::COUNTER_KEY)));

		$result = $qb->executeQuery();
		$value  = $result->fetchOne();
		$result->closeCursor();

		if ($value === false || $value === null) {
			return null;
		}

		return (int)$value;
	}//end readCounter()

	/**
	 * Set the stored counter back to 0 for a new year, in the database.
	 *
	 * Written straight to the table, not through IAppConfig, whose cache may
	 * still hold an older value and then skip the write. When the row does not
	 * exist yet the next allocation seeds it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/pos-receipt-engine/spec.md
	 */
	protected function resetCounter(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('appconfig')
			->set('configvalue', $qb->createNamedParameter('0'))
			->where($qb->expr()->eq('appid', $qb->createNamedParameter(AppIdentity::APP_ID)))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter(static::COUNTER_KEY)));

		$qb->executeStatement();
	}//end resetCounter()

	/**
	 * Conditional UPDATE of the counter row: set to $next only if it equals
	 * $expected. Returns the number of affected rows (1 = we won, 0 = lost race).
	 *
	 * @param int $expected The value we read.
	 * @param int $next The value to set.
	 *
	 * @return int The number of updated rows.
	 */
	protected function casCounter(int $expected, int $next): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update('appconfig')
			->set('configvalue', $qb->createNamedParameter((string)$next))
			->where($qb->expr()->eq('appid', $qb->createNamedParameter(AppIdentity::APP_ID)))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter(static::COUNTER_KEY)))
			->andWhere($qb->expr()->eq('configvalue', $qb->createNamedParameter((string)$expected)));

		return $qb->executeStatement();
	}//end casCounter()

	/**
	 * Create the counter row at value 1 when it does not exist yet.
	 *
	 * Uses appConfig as the canonical writer (it INSERTs the typed row). Called
	 * only when readCounter() found no row; the next() retry handles a
	 * concurrent caller that seeds first. Returns 1 when this call established
	 * the value 1, else 0.
	 *
	 * @return int 1 if this call set the value to 1, else 0.
	 *
	 * @spec openspec/specs/pos-receipt-engine/spec.md
	 */
	protected function seedCounter(): int {
		if ($this->readCounter() !== null) {
			return 0;
		}

		$this->appConfig->setValueInt(AppIdentity::APP_ID, static::COUNTER_KEY, 1);

		return 1;
	}//end seedCounter()
}//end class
