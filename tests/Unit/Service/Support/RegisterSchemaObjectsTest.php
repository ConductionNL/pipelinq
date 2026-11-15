<?php

/**
 * Behavioural tests for the RegisterSchemaObjects trait.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Support
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Support;

use OCA\Pipelinq\Service\Support\RegisterSchemaObjects;
use PHPUnit\Framework\TestCase;

/**
 * The findAll config built from the ids and extra filters, and the save target.
 *
 * @covers \OCA\Pipelinq\Service\Support\RegisterSchemaObjects
 */
class RegisterSchemaObjectsTest extends TestCase {

	/**
	 * @return void
	 */
	public function testFindAllIn(): void {
		$store = new class {
			/** @var array<int, array<string, mixed>> */
			public array $configs = [];

			/**
			 * @param array<string, mixed> $config The config.
			 * @return mixed
			 */
			public function findAll(array $config): mixed {
				$this->configs[] = $config;
				return ['row'];
			}

			/**
			 * @param array<string, mixed> $object   Object.
			 * @param array<int, mixed>    $extend   Extend.
			 * @param string               $register Register.
			 * @param string               $schema   Schema.
			 * @param string|null          $uuid     Uuid.
			 * @return array<int, mixed>
			 */
			public function saveObject(array $object, array $extend, string $register, string $schema, ?string $uuid): array {
				return [$object, $extend, $register, $schema, $uuid];
			}
		};
		$host = new class($store) {
			use RegisterSchemaObjects;

			/**
			 * @param object $store The object service.
			 */
			public function __construct(private object $store) {
			}

			/**
			 * @return object
			 */
			private function getObjectService(): object {
				return $this->store;
			}

			/**
			 * @param array<int, string>   $ids     The ids.
			 * @param array<string, mixed> $filters The filters.
			 * @return mixed
			 */
			public function run(array $ids, array $filters = []): mixed {
				return $this->findAllIn(ids: $ids, filters: $filters);
			}

			/**
			 * @param array<int, string> $ids   The ids.
			 * @param int                $limit The limit.
			 * @return mixed
			 */
			public function page(array $ids, int $limit): mixed {
				return $this->findPageIn(ids: $ids, limit: $limit);
			}

			/**
			 * @param array<int, string|null> $target The target.
			 * @param array<string, mixed>    $object The object.
			 * @return mixed
			 */
			public function save(array $target, array $object): mixed {
				return $this->saveIn(target: $target, object: $object);
			}
		};

		$this->assertSame(['row'], $host->run(['r', 's']));
		$host->run(['r', 's'], ['shift' => 'x', 'schema' => 'override']);
		$this->assertSame(
			[
				['filters' => ['register' => 'r', 'schema' => 's']],
				['filters' => ['register' => 'r', 'schema' => 'override', 'shift' => 'x']],
			],
			$store->configs
		);

		$store->configs = [];
		$this->assertSame(['row'], $host->page(['r', 's'], 50));
		$this->assertSame([['filters' => ['register' => 'r', 'schema' => 's'], 'limit' => 50]], $store->configs);

		$this->assertSame([['a' => 1], [], 'r', 's', null], $host->save(['r', 's', null], ['a' => 1]));
		$this->assertSame([['a' => 1], [], 'r', 's', 'u1'], $host->save(['r', 's', 'u1'], ['a' => 1]));
	}//end testFindAllIn()

	/**
	 * @return void
	 */
	public function testRowList(): void {
		$host = new class(new class {
			/**
			 * @param array<string, mixed> $config The config.
			 * @return mixed
			 */
			public function findAll(array $config): mixed {
				if (($config['fail'] ?? false) === true) {
					throw new \RuntimeException('down');
				}

				return $config['reply'];
			}
		}) {
			use RegisterSchemaObjects;

			/**
			 * @param object $store The object service.
			 */
			public function __construct(private object $store) {
			}

			/**
			 * @return object
			 */
			private function getObjectService(): object {
				return $this->store;
			}

			/**
			 * @param array<string, mixed> $config The config.
			 * @return array<int, mixed>
			 */
			public function rows(array $config): array {
				return $this->rowList(config: $config);
			}
		};

		$this->assertSame(['a', 'b'], $host->rows(['reply' => ['x' => 'a', 'y' => 'b']]));
		$this->assertSame([], $host->rows(['reply' => 'not a list']));
		$this->assertSame([], $host->rows(['fail' => true]));
	}//end testRowList()
}//end class
