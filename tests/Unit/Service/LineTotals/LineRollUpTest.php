<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/line-totals-roll-up/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\LineTotals;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Object\ObjectPatch;
use OCA\Pipelinq\Service\LineTotals\LineRollUp;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * The quote totals and lead value follow the lines.
 */
class LineRollUpTest extends TestCase {
	private const KEYS = ['register' => '1', 'salesQuoteLine_schema' => '40', 'leadProduct_schema' => '50'];

	private array $patched = [];

	private array $asked = [];

	/**
	 * A roll-up over these lines; the quote q-1 has a 9% VAT rate.
	 *
	 * @param array $lines The lines findAll returns.
	 */
	private function rollUp(array $lines): LineRollUp {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use ($lines): array {
				$this->asked = $config['filters'];
				return $lines;
			}
		);
		$objects->method('find')->willReturnCallback(static fn (): ObjectEntity => self::entity(schema: '30', data: ['vatRate' => 9]));
		$objects->method('patchObject')->willReturnCallback(
			function (ObjectPatch $patch): ObjectEntity {
				$this->patched[$patch->objectId] = $patch->data;
				return new ObjectEntity();
			}
		);
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => (self::KEYS[$key] ?? $default));

		return new LineRollUp(objects: $objects, appConfig: $config);
	}

	/**
	 * An object of a schema.
	 *
	 * @param string $schema The schema id.
	 * @param array  $data   The object data.
	 */
	private static function entity(string $schema, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}

	public function testAQuoteLineWritesTheQuoteTotalsAtItsVatRate(): void {
		$lines = [['quantity' => 2, 'unitPrice' => 100, 'discount' => 10], ['quantity' => 1, 'unitPrice' => 20]];
		$this->rollUp(lines: $lines)->apply(line: self::entity(schema: '40', data: ['quote' => 'q-1']));
		$this->assertSame(['register' => '1', 'schema' => '40', 'quote' => 'q-1'], $this->asked);
		$this->assertSame(['q-1' => ['subtotal' => 200.0, 'vatAmount' => 18.0, 'total' => 218.0]], $this->patched);
	}

	public function testALeadLineWritesTheLeadValue(): void {
		$this->rollUp(lines: [self::entity(schema: '50', data: ['total' => 3000])])->apply(line: self::entity(schema: '50', data: ['lead' => 'l-1']));
		$this->assertSame(['l-1' => ['calculatedValue' => 3000.0, 'value' => 3000.0]], $this->patched);
	}

	public function testALeadWithoutLinesKeepsItsTypedValue(): void {
		$this->rollUp(lines: [])->apply(line: self::entity(schema: '50', data: ['lead' => 'l-1']));
		$this->assertSame([], $this->patched);
	}

	public function testTheLastQuoteLineRemovedLeavesZero(): void {
		$this->rollUp(lines: [])->apply(line: self::entity(schema: '40', data: ['quote' => 'q-1']));
		$this->assertSame(['q-1' => ['subtotal' => 0.0, 'vatAmount' => 0.0, 'total' => 0.0]], $this->patched);
	}

	public function testAnyOtherObjectIsLeftAlone(): void {
		$this->rollUp(lines: [])->apply(line: self::entity(schema: '30', data: ['quote' => 'q-1']));
		$this->rollUp(lines: [])->apply(line: self::entity(schema: '40', data: []));
		$this->assertSame([], $this->patched);
	}
}
