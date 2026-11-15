<?php

/**
 * Behavioural tests for the PosObjectReadWrite trait.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Util
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

use OCA\Pipelinq\Service\Support\PosObjectReadWrite;
use OCP\AppFramework\OCS\OCSNotFoundException;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;

/**
 * fetchOne finds or throws; saveObjectFor strips @self and saves by id.
 *
 * @covers \OCA\Pipelinq\Service\Support\PosObjectReadWrite
 * @covers \OCA\Pipelinq\Util\PosObjectFetch
 */
class PosObjectReadWriteTest extends TestCase {

	/**
	 * @return void
	 */
	public function testFetchAndSave(): void {
		$store = new class {
			/** @var array<int, array<string, mixed>> */
			public array $calls = [];

			/**
			 * @param string $id       Id.
			 * @param string $register Register.
			 * @param string $schema   Schema.
			 * @return array<string, mixed>|null
			 */
			public function find(string $id, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true): ?array {
				$register = $options?->register;
				$schema = $options?->schema;
				if ($id === 'boom') {
					throw new \RuntimeException('x');
				}

				return ($id === 'known') ? ['id' => $id, 'at' => $register.'/'.$schema] : null;
			}

			/**
			 * @param array<string, mixed> $object   Object.
			 * @param array<int, string>   $extend   Extend.
			 * @param string               $register Register.
			 * @param string               $schema   Schema.
			 * @param string               $uuid     Uuid.
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true): array {
				$extend = $options->extend;
				$register = $options->target->register;
				$schema = $options->target->schema;
				$uuid = $options->uuid;
				$this->calls[] = [$object, $extend, $register, $schema, $uuid];
				return $object + ['uuid' => $uuid];
			}
		};

		$host = new class($store) {
			use PosObjectReadWrite;

			/**
			 * @param object $store The object service.
			 */
			public function __construct(private object $store) {
			}

			/**
			 * @param string $schemaKey The key.
			 * @return array{0: string, 1: string}
			 */
			private function config(string $schemaKey): array {
				return ['reg', $schemaKey];
			}

			/**
			 * @return object
			 */
			private function getObjectService(): object {
				return $this->store;
			}

			/**
			 * @param mixed $object The object.
			 * @return array<string, mixed>
			 */
			private function toArray(mixed $object): array {
				return (array)$object;
			}

			/**
			 * @param string $method  The method.
			 * @param mixed  ...$args The arguments.
			 * @return mixed
			 */
			public function call(string $method, mixed ...$args): mixed {
				return $this->$method(...$args);
			}
		};

		$this->assertSame(['id' => 'known', 'at' => 'reg/shift_schema'], $host->call('fetchOne', 'shift_schema', 'known', 'Not found'));
		$this->assertSame(['a' => 1, 'uuid' => 'u1'], $host->call('saveObjectFor', 'shift_schema', 'u1', ['a' => 1, '@self' => []]));
		$this->assertSame([['a' => 1], [], 'reg', 'shift_schema', 'u1'], $store->calls[0]);

		foreach (['missing', 'boom'] as $id) {
			try {
				$host->call('fetchOne', 'shift_schema', $id, 'Not found');
				$this->fail('expected a not-found error');
			} catch (OCSNotFoundException $e) {
				$this->assertSame('Not found', $e->getMessage());
			}
		}
	}//end testFetchAndSave()
}//end class
