<?php

/**
 * Golden test for LabelRenameService::rename() and rewriteAll().
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Labels
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/shared-label-list/specs/shared-labels/spec.md#scenario-a-renamed-label-follows-its-items
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Labels;

use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use OCA\Pipelinq\Service\Labels\LabelRenameService;
use OCP\App\IAppManager;
use OCP\BackgroundJob\IJobList;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every OpenRegister call (findAll configuration, saveObject payload and
 * target) and every queued job of a rename and of a background rewrite,
 * over items that carry the label once, twice, next to the new name, not at
 * all, on every target schema, with a failing save and past the inline
 * limit, compared with LabelRenameGolden.json. Set LABEL_RENAME_GOLDEN_RECORD=1
 * to rewrite the file, then run once more without it.
 *
 * @covers \OCA\Pipelinq\Service\Labels\LabelRenameService
 * @uses \OCA\Pipelinq\Util\SerialisedPayload
 * @uses \OCA\Pipelinq\Util\ObjectToPlainArray
 * @uses \OCA\Pipelinq\Util\SerialisedToArray
 * @uses \OCA\Pipelinq\Util\AnyPayloadToArray
 * @uses \OCA\Pipelinq\Util\CastingPayloadToArray
 * @uses \OCA\Pipelinq\Util\EntityPayload
 * @uses \OCA\Pipelinq\Util\NullablePayloadToArray
 * @uses \OCA\Pipelinq\Util\OptionalPayloadToArray
 * @uses \OCA\Pipelinq\Util\PayloadToArray
 *
 * @spec openspec/changes/shared-label-list/specs/shared-labels/spec.md#scenario-a-renamed-label-follows-its-items
 */
class LabelRenameGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/LabelRenameGolden.json';

	/**
	 * Calls made in the current case.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public static array $calls = [];

	/**
	 * Run every case and compare with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$items = [
			'lead' => [
				['id' => 'l1', 'labels' => ['Hot', 'Warm']],
				['id' => 'l2', 'labels' => ['Hot', 'Heet']],
				['id' => 'l3', 'labels' => ['Cold']],
				['@self' => ['id' => 'l4'], 'labels' => ['Hot', 'Hot']],
				['id' => 'fail', 'labels' => ['Hot']],
				['labels' => ['Hot']],
			],
			'client' => [['id' => 'c1', 'labels' => 'Hot'], ['id' => 'c2', 'labels' => [7, 'Hot']]],
			'article' => [['id' => 'a1', 'tags' => ['Hot']], ['id' => 'a2', 'labels' => ['Hot']]],
		];
		$many = ['lead' => [['id' => 'fail', 'labels' => ['Hot']]], 'client' => [], 'article' => []];
		for ($i = 0; $i < 230; $i++) {
			$many['lead'][] = ['id' => 'm' . $i, 'labels' => ['Hot']];
		}

		$cases = [
			'rename'                   => ['call' => 'rename', 'items' => $items],
			'rename, same name'        => ['call' => 'rename', 'items' => $items, 'name' => 'Hot'],
			'rename past inline limit' => ['call' => 'rename', 'items' => $many],
			'rewrite all'              => ['call' => 'rewriteAll', 'items' => $many],
			'rewrite all, small'       => ['call' => 'rewriteAll', 'items' => $items],
			'rename, article left'     => [
				'call' => 'rename',
				'items' => ['lead' => [['id' => 'l1', 'labels' => ['Hot']]], 'article' => [['id' => 'fail', 'tags' => ['Hot']]]],
			],
			'rename, filter ignored'   => ['call' => 'rename', 'items' => $items, 'ignoreFilter' => true],
		];

		$actual = [];
		foreach ($cases as $name => $case) {
			self::$calls = [];
			$service = $this->service(items: $case['items'], ignoreFilter: ($case['ignoreFilter'] ?? false));
			if ($case['call'] === 'rename') {
				$result = $service->rename(labelId: 'label-1', name: ($case['name'] ?? 'Heet'));
			} else {
				$result = $service->rewriteAll(from: 'Hot', to: 'Heet', userId: 'admin');
			}

			$calls = array_map(static fn (array $call): string => (string)json_encode($call), self::$calls);
			if (count($calls) > 40) {
				$calls = [
					'count' => count($calls),
					'first' => array_slice($calls, 0, 6),
					'last' => array_slice($calls, -6),
					'sha256' => hash('sha256', implode("\n", $calls)),
				];
			}

			$actual[$name] = ['result' => $result, 'calls' => $calls];
		}

		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (getenv('LABEL_RENAME_GOLDEN_RECORD') !== false) {
			file_put_contents(self::GOLDEN, json_encode($actual, $flags) . "\n");
			$this->markTestSkipped(message: 'recorded');
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		foreach ($expected as $name => $want) {
			$got = json_encode(($actual[$name] ?? null), $flags);
			$this->assertSame(expected: json_encode($want, $flags), actual: $got, message: $name);
		}

		$this->assertSame(expected: array_keys($expected), actual: array_keys($actual));
	}//end testGolden()

	/**
	 * A service over a store with the label `Hot` and the given items.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $items        Items per schema slug.
	 * @param boolean                                         $ignoreFilter Return every item, as OpenRegister does for a filter it does not apply.
	 *
	 * @return LabelRenameService
	 */
	private function service(array $items, bool $ignoreFilter): LabelRenameService {
		$store = new class($items, $ignoreFilter) {
			/**
			 * @param array<string, array<int, array<string, mixed>>> $items        Items per schema slug.
			 * @param boolean                                         $ignoreFilter Return every item.
			 */
			public function __construct(private array $items, private bool $ignoreFilter) {
			}

			/**
			 * @param string $id      The id.
			 * @param mixed  ...$args The register and schema.
			 *
			 * @return array<string, mixed>
			 */
			public function find(string $id, mixed ...$args): array {
				return ['id' => $id, 'name' => 'Hot'];
			}

			/**
			 * @param array<string, mixed> $config  The query configuration.
			 * @param mixed                ...$args Further arguments.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config, mixed ...$args): array {
				LabelRenameGoldenTest::$calls[] = ['findAll' => $config];
				$schema = (string)$config['filters']['schema'];
				if ($schema === LabelRenameService::LABEL_SCHEMA) {
					return [['id' => 'label-1', 'name' => 'Hot'], ['id' => 'label-2', 'name' => 'Cold']];
				}

				$wanted = array_diff_key($config['filters'], ['register' => true, 'schema' => true]);
				if ($this->ignoreFilter === true) {
					$wanted = [];
				}

				$rows = array_filter(
					($this->items[$schema] ?? []),
					static fn (array $item): bool => array_filter(
						$wanted,
						static fn (string $value, string $field): bool => in_array($value, (array)($item[$field] ?? []), true) === false,
						ARRAY_FILTER_USE_BOTH
					) === []
				);
				return array_slice(array_values($rows), 0, (int)$config['limit']);
			}

			/**
			 * @param array<string, mixed> $object  The object.
			 * @param mixed                ...$args The register, schema and uuid.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, mixed ...$args): array {
				if (($args['options'] ?? null) instanceof ObjectSaveOptions) {
					$options = $args['options'];
					unset($args['options']);
					$args = ['register' => $options->target->register, 'schema' => $options->target->schema, 'uuid' => $options->uuid] + $args;
				}

				LabelRenameGoldenTest::$calls[] = ['saveObject' => $object, 'args' => $args];
				$id = (string)($args['uuid'] ?? '');
				if ($id === 'fail') {
					throw new RuntimeException('locked');
				}

				$schema = (string)$args['schema'];
				foreach (($this->items[$schema] ?? []) as $index => $item) {
					if ((string)($item['id'] ?? ($item['@self']['id'] ?? '')) === $id) {
						$this->items[$schema][$index] = $object;
					}
				}

				return $object;
			}
		};

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($store);
		$apps = $this->createMock(originalClassName: IAppManager::class);
		$apps->method('isInstalled')->willReturn(true);
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'admin');
		$jobs = $this->createMock(originalClassName: IJobList::class);
		$jobs->method('add')->willReturnCallback(
			static function (string $job, mixed $argument): void {
				LabelRenameGoldenTest::$calls[] = ['jobAdd' => $job, 'argument' => $argument];
			}
		);

		return new LabelRenameService(
			container: $container,
			appManager: $apps,
			userSession: $session,
			groupManager: $groups,
			jobList: $jobs,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end service()
}//end class
