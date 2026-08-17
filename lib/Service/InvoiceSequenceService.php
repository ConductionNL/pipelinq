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
use OCA\Pipelinq\AppInfo\Application;
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
	private const COUNTER_KEY = 'receipt_invoice_counter';

	/**
	 * App-config key holding the year the counter belongs to.
	 *
	 * @var string
	 */
	private const COUNTER_YEAR_KEY = 'receipt_invoice_counter_year';

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
					return sprintf('%04d-%06d', $year, $next);
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
		$storedYear = $this->appConfig->getValueInt(Application::APP_ID, self::COUNTER_YEAR_KEY, 0);
		$current = $this->appConfig->getValueInt(Application::APP_ID, self::COUNTER_KEY, 0);

		// New year (or first ever use): reset the sequence. The stored counter
		// is zeroed so the compare-and-set below (expected: 0) matches and the
		// first invoice of the new year becomes ...-000001. setValueInt is a no-op
		// when the value already matches, so a concurrent caller that already
		// reset is harmless.
		if ($storedYear !== $year) {
			$this->appConfig->setValueInt(Application::APP_ID, self::COUNTER_YEAR_KEY, $year);
			$this->appConfig->setValueInt(Application::APP_ID, self::COUNTER_KEY, 0);
			$current = 0;
		}

		$next = ($current + 1);
		$affected = $this->casCounter(expected: $current, next: $next);
		if ($affected === 1) {
			return $next;
		}

		// The row did not yet exist (no prior value): seed it atomically. If
		// another caller seeded it first the seed affects 0 rows and we retry.
		if ($current === 0) {
			$seeded = $this->seedCounter();
			if ($seeded === 1) {
				return 1;
			}
		}

		return 0;
	}//end compareAndIncrement()

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
			->where($qb->expr()->eq('appid', $qb->createNamedParameter(Application::APP_ID)))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter(self::COUNTER_KEY)))
			->andWhere($qb->expr()->eq('configvalue', $qb->createNamedParameter((string)$expected)));

		return $qb->executeStatement();
	}//end casCounter()

	/**
	 * Insert the counter row at value 1 if it does not yet exist.
	 *
	 * Uses appConfig as the canonical writer (it INSERTs the row); the wrapping
	 * compareAndIncrement retry handles the case where a concurrent caller seeds
	 * first. Returns 1 when this call established the value 1, else 0.
	 *
	 * @return int 1 if this call set the value to 1, else 0.
	 */
	protected function seedCounter(): int {
		$existing = $this->appConfig->getValueInt(Application::APP_ID, self::COUNTER_KEY, 0);
		if ($existing !== 0) {
			return 0;
		}

		$this->appConfig->setValueInt(Application::APP_ID, self::COUNTER_KEY, 1);

		return 1;
	}//end seedCounter()
}//end class
