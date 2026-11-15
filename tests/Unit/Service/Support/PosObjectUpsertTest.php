<?php

/**
 * Behavioural tests for the PosObjectUpsert trait.
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

use OCA\Pipelinq\Service\Support\PosObjectUpsert;
use PHPUnit\Framework\TestCase;

/**
 * An id is kept; an empty one is replaced by a fresh UUID before the save.
 *
 * @covers \OCA\Pipelinq\Service\Support\PosObjectUpsert
 */
class PosObjectUpsertTest extends TestCase {

	/**
	 * @return void
	 */
	public function testUpsert(): void {
		$host = new class {
			use PosObjectUpsert;

			/** @var array<int, array<int, mixed>> */
			public array $saves = [];

			/**
			 * @param string               $id      The id.
			 * @param array<string, mixed> $payload The payload.
			 * @return array<string, mixed>
			 */
			public function save(string $id, array $payload): array {
				return $this->upsertObject(schemaKey: 'posRole_schema', id: $id, payload: $payload);
			}

			/**
			 * @param string $schemaKey The key.
			 * @return array{0: string, 1: string}
			 */
			private function config(string $schemaKey): array {
				return ['reg', $schemaKey];
			}

			/**
			 * @return string
			 */
			private function uuid(): string {
				return 'fresh';
			}

			/**
			 * @return object
			 */
			private function getObjectService(): object {
				return new class($this) {
					/**
					 * @param object $host The host.
					 */
					public function __construct(private object $host) {
					}

					/**
					 * @param array<string, mixed> $object   Object.
					 * @param array<int, string>   $extend   Extend.
					 * @param string               $register Register.
					 * @param string               $schema   Schema.
					 * @param string               $uuid     Uuid.
					 * @return array<string, mixed>
					 */
					public function saveObject(array $object, array $extend, string $register, string $schema, string $uuid): array {
						$this->host->saves[] = [$register, $schema, $uuid];
						return $object + ['id' => $uuid];
					}
				};
			}

			/**
			 * @param mixed $object The object.
			 * @return array<string, mixed>
			 */
			private function toArray(mixed $object): array {
				return (array)$object;
			}
		};

		$this->assertSame(['a' => 1, 'id' => 'r1'], $host->save('r1', ['a' => 1]));
		$this->assertSame(['a' => 2, 'id' => 'fresh'], $host->save('', ['a' => 2]));
		$this->assertSame([['reg', 'posRole_schema', 'r1'], ['reg', 'posRole_schema', 'fresh']], $host->saves);
	}//end testUpsert()
}//end class
