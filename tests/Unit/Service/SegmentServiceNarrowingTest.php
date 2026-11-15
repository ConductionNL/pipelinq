<?php

/**
 * Unit tests for how SegmentService narrows the rows it reads with OpenRegister's
 * `_where` (segment-groups-its-rules, design D4), and that narrowing never
 * changes who is in a segment.
 *
 * OpenRegister is played by a fake object service that applies `_where` the
 * way its condition builder does: `like` ignores case, `eq` and `ne` do not,
 * comparisons and `ne` never match a missing value, `isnull` asks for one.
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

use OCA\Pipelinq\Service\Marketing\ListQueryWhereCapability;
use OCA\Pipelinq\Service\Marketing\SegmentQueryCompiler;
use OCA\Pipelinq\Service\Marketing\SegmentRuleTree;
use OCA\Pipelinq\Service\Marketing\SegmentSignalService;
use OCA\Pipelinq\Service\SchemaMapService;
use OCA\Pipelinq\Service\SegmentService;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A last-contact signal read from the row, so a tree can mix a signal with stored properties.
 */
class NarrowingSignals extends SegmentSignalService {

	/**
	 * Construct without the real collaborators.
	 */
	public function __construct() {
	}//end __construct()

	/**
	 * One signal.
	 *
	 * @return array<string, array{type: string, title: string, source: string, description: string}>
	 */
	public function catalogue(): array {
		return ['pipelinqLastContactAt' => ['type' => 'string', 'title' => 'Last contact', 'source' => 'pipelinq', 'description' => 'Test signal.']];
	}//end catalogue()

	/**
	 * The row's fixture value.
	 *
	 * @param string               $field  The signal.
	 * @param array<string, mixed> $entity The row.
	 *
	 * @return mixed
	 */
	public function valueFor(string $field, array $entity): mixed {
		return ($entity['lastContactFixture'] ?? null);
	}//end valueFor()
}//end class

/**
 * @covers \OCA\Pipelinq\Service\SegmentService
 * @uses   \OCA\Pipelinq\Service\Marketing\ListQueryWhereCapability
 * @uses   \OCA\Pipelinq\Service\Marketing\SegmentQueryCompiler
 * @uses   \OCA\Pipelinq\Service\Marketing\SegmentRuleTree
 * @uses   \OCA\Pipelinq\Service\SegmentRulePosition
 * @uses   \OCA\Pipelinq\Service\SegmentRuleLeaf
 * @uses   \OCA\Pipelinq\Util\PayloadToArray
 * @uses   \OCA\Pipelinq\Util\EntityPayload
 * @uses   \OCA\Pipelinq\Util\SerialisedPayload
 * @uses   \OCA\Pipelinq\Service\Marketing\SegmentSignalService
 */
class SegmentServiceNarrowingTest extends TestCase {

	/**
	 * The client and contact properties.
	 */
	private const PROPERTIES = [
		'name' => ['type' => 'string'],
		'type' => ['type' => 'string'],
		'address' => ['type' => 'string'],
		'place' => ['type' => 'string'],
		'industry' => ['type' => 'array', 'items' => ['type' => 'string']],
		'employees' => ['type' => 'integer'],
		'lifecycleStage' => ['type' => 'string'],
		'preferredChannel' => ['type' => 'string'],
	];

	/**
	 * The configs findAll() was called with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $reads = [];

	/**
	 * Whether a read carrying `_where` throws, as OpenRegister does on a refused leaf.
	 *
	 * @var boolean
	 */
	public bool $whereFails = false;

	/**
	 * The audience: case variants, missing values, lists and numbers.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function rows(): array {
		return [
			['name' => 'Vereniging De Linde', 'type' => 'organization', 'industry' => ['Vereniging', 'sport'], 'address' => 'Lindelaan 4', 'place' => 'Zuiddrecht', 'employees' => 3, 'lifecycleStage' => 'customer'],
			['name' => 'Bakkerij Markt', 'type' => 'Organization', 'industry' => ['horeca'], 'address' => 'Markt 1', 'place' => 'zuiddrecht', 'employees' => 12, 'lifecycleStage' => 'Customer', 'lastContactFixture' => '2025-12-01'],
			['name' => 'Café Oost', 'type' => 'organization', 'industry' => ['horeca', 'detailhandel'], 'address' => 'Oostweg 9', 'place' => 'Oost', 'employees' => 6, 'lastContactFixture' => '2025-10-01'],
			['name' => 'Jan Lindelaan', 'type' => 'person', 'address' => 'lindelaan 12', 'place' => 'Zuiddrecht', 'lifecycleStage' => 'lead', 'preferredChannel' => 'counter'],
			['name' => 'Piet', 'type' => 'person', 'place' => null, 'lifecycleStage' => 'lead', 'preferredChannel' => 'Counter', 'lastContactFixture' => '2026-01-10'],
			['name' => 'Anoniem'],
			['name' => 'Winkel aan de Markt', 'type' => 'organization', 'industry' => ['detailhandel'], 'address' => 'Grote Markt 2', 'place' => 'Zuiddrecht', 'employees' => 40, 'lifecycleStage' => 'customer', 'preferredChannel' => 'email'],
			['name' => 'Stichting Vereniging', 'type' => 'ORGANIZATION', 'industry' => 'vereniging', 'address' => 'Kerkstraat 1', 'place' => 'Zuiddrecht', 'employees' => 9],
		];
	}//end rows()

	/**
	 * The segments shipped in the Zuiddrecht example set, plus trees that use every compiled operator.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public static function segments(): array {
		$segments = [];
		foreach (['40-marketing.json', '81-warmtenet-newsletter.json'] as $file) {
			$data = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/example-sets/zuiddrecht.d/' . $file), true);
			self::collectSegments(node: $data, found: $segments);
		}

		$segments['board'] = [
			'customer',
			[
				'type' => 'AND',
				'children' => [
					['field' => 'place', 'operator' => 'equals', 'value' => 'ZUIDDRECHT'],
					['type' => 'OR', 'children' => [['field' => 'industry', 'operator' => 'contains', 'value' => 'horeca'], ['field' => 'industry', 'operator' => 'contains', 'value' => 'detailhandel']]],
					['field' => 'pipelinqLastContactAt', 'operator' => 'after', 'value' => '2025-11-01'],
				],
			],
		];
		$segments['not equals and between'] = [
			'customer',
			[
				'type' => 'AND',
				'children' => [
					['field' => 'place', 'operator' => 'notEquals', 'value' => 'oost'],
					['field' => 'employees', 'operator' => 'between', 'value' => [3, 12]],
				],
			],
		];
		$segments['or of sendable leaves'] = [
			'contact',
			[
				'type' => 'OR',
				'children' => [
					['field' => 'preferredChannel', 'operator' => 'equals', 'value' => 'counter'],
					['field' => 'employees', 'operator' => 'gte', 'value' => 40],
					['field' => 'place', 'operator' => 'isNull'],
				],
			],
		];
		$segments['at least five employees'] = ['customer', ['type' => 'AND', 'children' => [['field' => 'employees', 'operator' => 'gte', 'value' => 5]]]];
		$segments['not'] = [
			'customer',
			['type' => 'AND', 'children' => [['type' => 'NOT', 'children' => [['field' => 'type', 'operator' => 'equals', 'value' => 'person']]], ['field' => 'employees', 'operator' => 'lt', 'value' => 10]]],
		];

		return $segments;
	}//end segments()

	/**
	 * Find every segment object in an example-set fragment.
	 *
	 * @param mixed                                              $node  A JSON node.
	 * @param array<string, array{0: string, 1: array<string, mixed>}> $found Where they go.
	 *
	 * @return void
	 */
	private static function collectSegments(mixed $node, array &$found): void {
		if (is_array($node) === false) {
			return;
		}

		if (isset($node['rules'], $node['entityType'], $node['name']) === true && is_array($node['rules']) === true) {
			$found[(string)$node['name']] = [(string)$node['entityType'], $node['rules']];
		}

		foreach ($node as $child) {
			self::collectSegments(node: $child, found: $found);
		}
	}//end collectSegments()

	/**
	 * The service, with OpenRegister taking `_where` or not.
	 *
	 * @param bool $where Whether OpenRegister lists `listQuery.where`.
	 *
	 * @return SegmentService
	 */
	private function service(bool $where): SegmentService {
		$objectService = new class ($this) {
			public function __construct(private SegmentServiceNarrowingTest $test) {
			}

			public function findAll(array $config = []): array {
				$this->test->reads[] = $config;
				if (isset($config['where']) === false) {
					return SegmentServiceNarrowingTest::rows();
				}

				if ($this->test->whereFails === true) {
					throw new RuntimeException('400: the leaf at $.children[0] names an encrypted property');
				}

				return array_values(array_filter(SegmentServiceNarrowingTest::rows(), fn (array $row): bool => SegmentServiceNarrowingTest::whereMatches(node: $config['where'], row: $row)));
			}
		};
		$schemaMapper = new class {
			public function find(string|int $id, ?array $_extend = [], bool $_rbac = true, bool $_multitenancy = true): object {
				return new class {
					public function getProperties(): array {
						return SegmentServiceNarrowingTest::properties();
					}
				};
			}
		};
		$capabilities = new class ($where) {
			public function __construct(private bool $where) {
			}

			public function publicCapabilities(): array {
				return ['listQuery' => ['where' => $this->where]];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				'OCA\\OpenRegister\\Service\\ObjectService' => $objectService,
				'OCA\\OpenRegister\\Db\\SchemaMapper' => $schemaMapper,
				'OCA\\OpenRegister\\Service\\ApiVersion\\ApiCapabilitiesServiceInterface' => $capabilities,
				default => throw new RuntimeException('not registered: ' . $id),
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);

		$cache = $this->createMock(ICache::class);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createLocal')->willReturn($cache);
		$logger = $this->createMock(LoggerInterface::class);

		return new SegmentService(
			$container,
			$appConfig,
			$this->createMock(SchemaMapService::class),
			$cacheFactory,
			$logger,
			new NarrowingSignals(),
			new SegmentRuleTree(),
			new ListQueryWhereCapability($container, $cacheFactory, $logger),
			new SegmentQueryCompiler()
		);
	}//end service()

	/**
	 * The schema properties.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function properties(): array {
		return self::PROPERTIES;
	}//end properties()

	/**
	 * Whether a row matches a `_where` node, as OpenRegister's condition builder reads it.
	 *
	 * @param array<string, mixed> $node A group or a leaf.
	 * @param array<string, mixed> $row  The row.
	 *
	 * @return bool
	 */
	public static function whereMatches(array $node, array $row): bool {
		if (isset($node['type']) === true) {
			$results = array_map(static fn (array $child): bool => self::whereMatches(node: $child, row: $row), $node['children']);
			return $node['type'] === 'AND' ? !in_array(false, $results, true) : in_array(true, $results, true);
		}

		$actual = ($row[$node['field']] ?? null);
		$text = is_array($actual) === true ? json_encode($actual) : (string)$actual;
		return match ($node['operator']) {
			'like' => $actual !== null && stripos($text, (string)$node['value']) !== false,
			'eq' => $actual !== null && $text === (string)$node['value'],
			'ne' => $actual !== null && $text !== (string)$node['value'],
			'gt' => is_numeric($actual) && $actual > $node['value'],
			'gte' => is_numeric($actual) && $actual >= $node['value'],
			'lt' => is_numeric($actual) && $actual < $node['value'],
			'lte' => is_numeric($actual) && $actual <= $node['value'],
			'isnull' => ($actual === null) === $node['value'],
		};
	}//end whereMatches()

	/**
	 * Every shipped segment and every compiled operator gives the same size with and without `_where`.
	 *
	 * @return void
	 */
	public function testCompiledAndPhpPathsAgreeOnSeedSegments(): void {
		$segments = self::segments();
		$this->assertGreaterThanOrEqual(6, count($segments) - 5, 'The example set\'s segments were not found.');

		foreach ($segments as $name => [$entityType, $rules]) {
			$this->reads = [];
			$narrowed = $this->service(where: true)->previewRulePayload(rules: $rules, entityType: $entityType);
			$sent = array_column($this->reads, 'where');
			$this->reads = [];
			$plain = $this->service(where: false)->previewRulePayload(rules: $rules, entityType: $entityType);

			$this->assertTrue($plain['valid'], $name . ': ' . (string)$plain['error']);
			$this->assertSame($plain['estimatedSize'], $narrowed['estimatedSize'], $name);
			$this->assertSame([], array_column($this->reads, 'where'), $name . ': without the capability nothing is sent');
			if ($name !== 'not') {
				$this->assertCount(1, $sent, $name . ': the rows were not narrowed');
			}
		}
	}//end testCompiledAndPhpPathsAgreeOnSeedSegments()

	/**
	 * The board's segment reads only the rows in Zuiddrecht and checks the signal in PHP.
	 *
	 * @return void
	 */
	public function testTheBoardSegmentReadsOnlyTheNarrowedRows(): void {
		$rules = self::segments()['board'][1];
		$service = $this->service(where: true);

		$members = array_column(
			array_filter(self::rows(), fn (array $row): bool => $service->evaluateRules(rules: $rules, entity: $row)),
			'name'
		);
		$result = $service->previewRulePayload(rules: $rules, entityType: 'customer');

		$this->assertSame(['Bakkerij Markt'], $members);
		$this->assertSame(1, $result['estimatedSize']);
		$this->assertSame(['type' => 'AND', 'children' => [['field' => 'place', 'operator' => 'like', 'value' => 'ZUIDDRECHT']]], $this->reads[0]['where']);
		$this->assertSame(['register' => 'pipelinq', 'schema' => 'client'], $this->reads[0]['filters']);
	}//end testTheBoardSegmentReadsOnlyTheNarrowedRows()

	/**
	 * A narrowed read OpenRegister refuses falls back to every row, with the same members.
	 *
	 * @return void
	 */
	public function testARefusedNarrowedReadFallsBackToEveryRow(): void {
		$this->whereFails = true;
		$rules = self::segments()['not equals and between'][1];

		$result = $this->service(where: true)->previewRulePayload(rules: $rules, entityType: 'customer');

		// Café Oost is out (same place, other case) and so is the shop with 40; a row
		// without employees is in, because the engine counts a missing number as in range.
		$this->assertSame(6, $result['estimatedSize']);
		$this->assertCount(2, $this->reads);
		$this->assertArrayHasKey('where', $this->reads[0]);
		$this->assertArrayNotHasKey('where', $this->reads[1]);
	}//end testARefusedNarrowedReadFallsBackToEveryRow()
}//end class
