<?php

/**
 * Unit tests for TicketReferenceSequence.
 *
 * The allocation runs for real over an in-memory counter store, as in
 * InvoiceSequenceServiceTest; only the two database steps are replaced.
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

use OCA\Pipelinq\Service\TicketReferenceSequence;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * In-memory double: the counter row lives in an array under the sequence's own key.
 */
final class InMemoryTicketReferenceSequence extends TicketReferenceSequence {
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
	 * @param int $next The value to set.
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
		if (($this->store[static::COUNTER_KEY] ?? 0) !== 0) {
			return 0;
		}

		$this->store[static::COUNTER_KEY] = 1;
		return 1;
	}
}

/**
 * Tests for TicketReferenceSequence.
 *
 * @spec openspec/changes/ticket-leaf-closed-reference-assignee/specs/ticket-leaf/spec.md#requirement-a-ticket-carries-a-reference-number
 */
class TicketReferenceSequenceTest extends TestCase {
	/**
	 * References count up per year as PQ-YYYY-NNNN, on the ticket's own counter.
	 *
	 * @return void
	 */
	public function testReferencesCountUpOnTheirOwnCounter(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$sequence = new InMemoryTicketReferenceSequence($this->createMock(IDBConnection::class), $appConfig, $this->createMock(LoggerInterface::class));
		$sequence->store['receipt_invoice_counter'] = 41;
		$appConfig->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default = 0): int => ($sequence->store[$key] ?? $default)
		);
		$appConfig->method('setValueInt')->willReturnCallback(
			static function (string $app, string $key, int $value) use ($sequence): bool {
				$sequence->store[$key] = $value;
				return true;
			}
		);

		$year = date('Y');
		$this->assertSame('PQ-'.$year.'-0001', $sequence->next());
		$this->assertSame('PQ-'.$year.'-0002', $sequence->next());
		$this->assertSame(2, $sequence->store['ticket_reference_counter']);
		$this->assertSame(41, $sequence->store['receipt_invoice_counter'], 'the invoice counter is not touched');
	}//end testReferencesCountUpOnTheirOwnCounter()
}//end class
