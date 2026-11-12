<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\LineTotals;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Service\LineTotals\LineRollUp;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * The roll-up's fallbacks: VAT rate, missing parents, unconfigured schemas,
 * plain-array rows and leads whose value was typed over.
 *
 * @covers \OCA\Pipelinq\Service\LineTotals\LineRollUp
 * @uses   \OCA\Pipelinq\Service\LineTotals\LineTotalMath
 * @uses   \OCA\Pipelinq\Service\LineTotals\LineParent
 */
class LineRollUpEdgeCasesTest extends TestCase {
	private const KEYS = ['register' => '7', 'salesQuoteLine_schema' => '40', 'leadProduct_schema' => '50'];

	/** @var array<string, array> */
	private array $patched = [];

	/** @var array<int, array> */
	private array $findAllConfigs = [];

	/** @var array<int, string> */
	private array $foundIds = [];

	/** @var array<int, array> */
	private array $patchFlags = [];

	/**
	 * A roll-up whose store returns these lines and this parent.
	 *
	 * @param array             $lines  What findAll returns.
	 * @param ObjectEntity|null $parent What find returns.
	 * @param array             $keys   The app config values.
	 */
	private function rollUp(array $lines, ?ObjectEntity $parent, array $keys = self::KEYS): LineRollUp {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config, bool $_rbac = true, bool $_multitenancy = true) use ($lines): array {
				$this->findAllConfigs[] = ['config' => $config, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				return $lines;
			}
		);
		$objects->method('find')->willReturnCallback(
			function (string|int $id) use ($parent): ?ObjectEntity {
				$this->foundIds[] = (string)$id;
				return $parent;
			}
		);
		$objects->method('patchObject')->willReturnCallback(
			function (string $objectId, array $data, string|int|null $register = null, string|int|null $schema = null, bool $_rbac = true, bool $_multitenancy = true): ObjectEntity {
				$this->patched[$objectId] = $data;
				$this->patchFlags[]       = ['rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				return new ObjectEntity();
			}
		);
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($keys[$key] ?? $default)
		);

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

	public function testANonNumericVatRateFallsBackToTwentyOnePercent(): void {
		$parent = self::entity(schema: '30', data: ['vatRate' => 'high']);
		$this->rollUp(lines: [['quantity' => 1, 'unitPrice' => 100]], parent: $parent)
			->apply(line: self::entity(schema: '40', data: ['quote' => 'q-1']));

		$this->assertSame(['q-1' => ['subtotal' => 100.0, 'vatAmount' => 21.0, 'total' => 121.0]], $this->patched);
		$this->assertSame(['q-1'], $this->foundIds);
	}

	public function testAMissingQuoteFallsBackToTwentyOnePercent(): void {
		$this->rollUp(lines: [['quantity' => 2, 'unitPrice' => 50]], parent: null)
			->apply(line: self::entity(schema: '40', data: ['quote' => 'q-9']));

		$this->assertSame(['q-9' => ['subtotal' => 100.0, 'vatAmount' => 21.0, 'total' => 121.0]], $this->patched);
	}

	public function testANumericStringVatRateIsUsed(): void {
		$parent = self::entity(schema: '30', data: ['vatRate' => '0']);
		$this->rollUp(lines: [['quantity' => 3, 'unitPrice' => 10]], parent: $parent)
			->apply(line: self::entity(schema: '40', data: ['quote' => 'q-1']));

		$this->assertSame(['q-1' => ['subtotal' => 30.0, 'vatAmount' => 0.0, 'total' => 30.0]], $this->patched);
	}

	public function testPlainArrayRowsAreReadAsLineData(): void {
		$this->rollUp(lines: [['total' => 10.5], ['total' => 4.5]], parent: null)
			->apply(line: self::entity(schema: '50', data: ['lead' => 'l-1']));

		$this->assertSame(['l-1' => ['calculatedValue' => 15.0, 'value' => 15.0]], $this->patched);
	}

	public function testALeadWhoseValueWasTypedOverOnlyGetsTheCalculatedValue(): void {
		$lead = self::entity(schema: '20', data: ['value' => 5000, 'calculatedValue' => 1200]);
		$this->rollUp(lines: [['total' => 1500]], parent: $lead)
			->apply(line: self::entity(schema: '50', data: ['lead' => 'l-1']));

		$this->assertSame(['l-1' => ['calculatedValue' => 1500.0]], $this->patched);
		$this->assertSame(['l-1'], $this->foundIds);
	}

	public function testTheLinesAreQueriedAndPatchedWithoutRbacOrMultitenancy(): void {
		$this->rollUp(lines: [['total' => 1]], parent: null)
			->apply(line: self::entity(schema: '50', data: ['lead' => ' l-2 ']));

		$this->assertSame(
			[['config' => ['filters' => ['register' => '7', 'schema' => '50', 'lead' => 'l-2'], 'limit' => 500], 'rbac' => false, 'multitenancy' => false]],
			$this->findAllConfigs
		);
		$this->assertSame([['rbac' => false, 'multitenancy' => false]], $this->patchFlags);
		$this->assertSame(['l-2'], array_keys($this->patched));
	}

	public function testABlankParentIdIsIgnored(): void {
		$this->rollUp(lines: [['total' => 1]], parent: null)
			->apply(line: self::entity(schema: '40', data: ['quote' => '   ']));

		$this->assertSame([], $this->patched);
		$this->assertSame([], $this->findAllConfigs);
	}

	public function testUnconfiguredSchemasNeverMatchASchemalessLine(): void {
		$this->rollUp(lines: [['total' => 1]], parent: null, keys: ['register' => '7'])
			->apply(line: self::entity(schema: '', data: ['quote' => 'q-1', 'lead' => 'l-1']));

		$this->assertSame([], $this->patched);
		$this->assertSame([], $this->findAllConfigs);
	}
}
