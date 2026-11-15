<?php

/**
 * Unit tests for QuoteNumberSequence.
 *
 * The allocation runs for real over an in-memory counter store; only the two
 * database steps are replaced.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\QuoteNumberSequence;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * In-memory double: the counter row lives in an array under the sequence's own key.
 */
final class InMemoryQuoteNumberSequence extends QuoteNumberSequence {
	/**
	 * The simulated oc_appconfig counter store.
	 *
	 * @var array<string, int>
	 */
	public array $store = [];

	/**
	 * Compare-and-set against the in-memory store.
	 *
	 * @param int $expected The value we read.
	 * @param int $next     The value to set.
	 *
	 * @return int 1 when the update applied, else 0.
	 */
	protected function casCounter(int $expected, int $next): int {
		if (($this->store[static::COUNTER_KEY] ?? 0) !== $expected) {
			return 0;
		}

		$this->store[static::COUNTER_KEY] = $next;
		return 1;
	}

	/**
	 * Seed the in-memory counter at 1 if absent.
	 *
	 * @return int 1 when this call set the value to 1, else 0.
	 */
	protected function seedCounter(): int {
		$seeded = (($this->store[static::COUNTER_KEY] ?? 0) === 0);
		$this->store[static::COUNTER_KEY] = ($this->store[static::COUNTER_KEY] ?? 1);
		return (int)$seeded;
	}
}

/**
 * Tests for QuoteNumberSequence.
 *
 * @spec openspec/specs/product-catalog-quoting/spec.md#requirement-quote-entity-enterprise
 */
class QuoteNumberSequenceTest extends TestCase {
	/**
	 * Quote numbers count up per year as OFF-YYYY-NNNN, on the quote's own counter.
	 *
	 * @return void
	 */
	public function testQuoteNumbersCountUpOnTheirOwnCounter(): void {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$sequence = new InMemoryQuoteNumberSequence(
			db: $this->createMock(originalClassName: IDBConnection::class),
			appConfig: $appConfig,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
		$sequence->store = ['ticket_reference_counter' => 401, 'quote_number_counter' => 41, 'quote_number_counter_year' => (int)date(format: 'Y')];
		$appConfig->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default = 0): int => ($sequence->store[$key] ?? $default)
		);
		$appConfig->method('setValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $value): bool => (($sequence->store[$key] = $value) !== null)
		);

		$year = date(format: 'Y');
		$this->assertSame(expected: 'OFF-'.$year.'-0042', actual: $sequence->next());
		$this->assertSame(expected: 'OFF-'.$year.'-0043', actual: $sequence->next());
		$this->assertSame(expected: 401, actual: $sequence->store['ticket_reference_counter'], message: 'the ticket counter is not touched');
	}//end testQuoteNumbersCountUpOnTheirOwnCounter()
}//end class
