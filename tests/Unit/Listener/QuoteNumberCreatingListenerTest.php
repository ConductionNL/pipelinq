<?php

/**
 * Unit tests for QuoteNumberCreatingListener.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
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

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\Pipelinq\Listener\QuoteNumberCreatingListener;
use OCA\Pipelinq\Service\QuoteNumberSequence;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A new quote is stored with its OFF number.
 *
 * @spec openspec/specs/product-catalog-quoting/spec.md#requirement-quote-entity-enterprise
 */
class QuoteNumberCreatingListenerTest extends TestCase {
	/**
	 * The listener over quote schema "70", with a sequence that hands out OFF-2026-0042 or fails.
	 *
	 * @param bool $allocates Whether the sequence hands out a number.
	 *
	 * @return QuoteNumberCreatingListener The listener.
	 */
	private function listener(bool $allocates = true): QuoteNumberCreatingListener {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (['salesQuote_schema' => '70'][$key] ?? $default)
		);
		$sequence = $this->createMock(originalClassName: QuoteNumberSequence::class);
		$sequence->method('next')->willReturnCallback(
			static function () use ($allocates): string {
				if ($allocates === false) {
					throw new RuntimeException(message: 'busy');
				}

				return 'OFF-2026-0042';
			}
		);

		return new QuoteNumberCreatingListener(
			appConfig: $config,
			sequence: $sequence
		);
	}//end listener()

	/**
	 * A creating event for an object of the given schema with the given data.
	 *
	 * @param string               $schema The schema id.
	 * @param array<string, mixed> $data   The object data.
	 *
	 * @return ObjectCreatingEvent The event.
	 */
	private function creating(string $schema, array $data): ObjectCreatingEvent {
		$quote = new ObjectEntity();
		$quote->setSchema($schema);
		$quote->setObject($data);
		return new ObjectCreatingEvent($quote);
	}//end creating()

	/**
	 * A new quote gets the next OFF number, and an earlier hook's change stays.
	 *
	 * @return void
	 */
	public function testANewQuoteGetsItsNumber(): void {
		$event = $this->creating(schema: '70', data: ['title' => 'Koppeling zorgplanning']);
		$event->setModifiedData(['lead' => 'l-1']);

		$this->listener()->handle($event);

		$this->assertSame(expected: ['lead' => 'l-1', 'number' => 'OFF-2026-0042'], actual: $event->getModifiedData());
	}//end testANewQuoteGetsItsNumber()

	/**
	 * Another schema, a quote that has a number, or a failed allocation write nothing.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: bool}>
	 */
	public static function untouched(): array {
		return [
			'another schema' => ['71', ['title' => 'Lead'], true],
			'already numbered' => ['70', ['number' => 'OFF-2025-0310'], true],
			'allocation fails' => ['70', ['title' => 'Offerte'], false],
		];
	}//end untouched()

	/**
	 * The cases of untouched() leave the save as it was.
	 *
	 * @param string               $schema    The schema id.
	 * @param array<string, mixed> $data      The object data.
	 * @param bool                 $allocates Whether the sequence hands out a number.
	 *
	 * @return void
	 *
	 * @dataProvider untouched
	 */
	public function testOthersAreLeftAlone(string $schema, array $data, bool $allocates): void {
		$event = $this->creating(schema: $schema, data: $data);

		$this->listener(allocates: $allocates)->handle($event);

		$this->assertSame(expected: [], actual: $event->getModifiedData());
	}//end testOthersAreLeftAlone()
}//end class
