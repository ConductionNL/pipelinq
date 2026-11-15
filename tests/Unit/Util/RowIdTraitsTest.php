<?php

/**
 * Behavioural tests for the row-identity traits.
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

namespace OCA\Pipelinq\Tests\Unit\Util;

use OCA\Pipelinq\Util\EntityIdOf;
use OCA\Pipelinq\Util\NullableRowIdOf;
use OCA\Pipelinq\Util\RowIdentity;
use OCA\Pipelinq\Util\RowIdOf;
use OCA\Pipelinq\Util\SerialisedPayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @self.id beats @self.uuid beats id beats uuid; a miss is null or ''.
 *
 * @covers \OCA\Pipelinq\Util\RowIdentity
 * @covers \OCA\Pipelinq\Util\RowIdOf
 * @covers \OCA\Pipelinq\Util\NullableRowIdOf
 * @covers \OCA\Pipelinq\Util\EntityIdOf
 * @uses   \OCA\Pipelinq\Util\SerialisedPayload
 */
class RowIdTraitsTest extends TestCase {

	/**
	 * Rows and the identity each yields.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: ?string}>
	 */
	public static function rows(): array {
		return [
			'@self.id first'                  => [['@self' => ['id' => 's1', 'uuid' => 'su'], 'id' => 'r1'], 's1'],
			'@self.uuid when no @self.id'     => [['@self' => ['uuid' => 'su'], 'id' => 'r1'], 'su'],
			'@self without ids falls to id'   => [['@self' => ['name' => 'x'], 'id' => 'r1'], 'r1'],
			'@self not an array falls to id'  => [['@self' => 'nope', 'id' => 'r1'], 'r1'],
			'id before uuid'                  => [['id' => 'r1', 'uuid' => 'ru'], 'r1'],
			'uuid when no id'                 => [['uuid' => 'ru'], 'ru'],
			'integer id is stringified'       => [['id' => 7], '7'],
			'null @self.id falls to @self.uuid' => [['@self' => ['id' => null, 'uuid' => 'su']], 'su'],
			'no identity'                     => [['name' => 'x'], null],
			'empty row'                       => [[], null],
		];
	}//end rows()

	/**
	 * RowIdentity resolves in precedence order, NullableRowIdOf keeps the null.
	 *
	 * @param array<string, mixed> $row      The row.
	 * @param string|null          $expected The identity.
	 *
	 * @return void
	 */
	#[DataProvider('rows')]
	public function testRowIdentityAndNullableIdOf(array $row, ?string $expected): void {
		$identity = new class {
			use RowIdentity;

			/**
			 * @param array<string, mixed> $row The row.
			 * @return string|null
			 */
			public function run(array $row): ?string {
				return $this->rowIdentity(row: $row);
			}
		};
		$nullable = new class {
			use NullableRowIdOf;

			/**
			 * @param array<string, mixed> $row The row.
			 * @return string|null
			 */
			public function run(array $row): ?string {
				return $this->idOf(object: $row);
			}
		};

		$this->assertSame($expected, $identity->run($row));
		$this->assertSame($expected, $nullable->run($row));
	}//end testRowIdentityAndNullableIdOf()

	/**
	 * RowIdOf turns a missing identity into ''.
	 *
	 * @param array<string, mixed> $row      The row.
	 * @param string|null          $expected The identity.
	 *
	 * @return void
	 */
	#[DataProvider('rows')]
	public function testRowIdOfUsesAnEmptyStringForAMiss(array $row, ?string $expected): void {
		$host = new class {
			use RowIdOf;

			/**
			 * @param array<string, mixed> $row The row.
			 * @return string
			 */
			public function run(array $row): string {
				return $this->idOf(object: $row);
			}
		};

		$this->assertSame(($expected ?? ''), $host->run($row));
	}//end testRowIdOfUsesAnEmptyStringForAMiss()

	/**
	 * EntityIdOf converts through the host's toArray() first.
	 *
	 * @return void
	 */
	public function testEntityIdOfReadsThroughTheHostsToArray(): void {
		$host = new class {
			use EntityIdOf;
			use SerialisedPayload;

			/**
			 * @param mixed $object The value.
			 * @return array<mixed>|null
			 */
			private function toArray(mixed $object): ?array {
				return $this->serialisedPayload(value: $object);
			}

			/**
			 * @param array<mixed>|object $object The value.
			 * @return string
			 */
			public function run(array|object $object): string {
				return $this->idOf(object: $object);
			}
		};

		$entity = new class implements \JsonSerializable {
			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['@self' => ['id' => 'e1'], 'id' => 'other'];
			}
		};

		$this->assertSame('e1', $host->run($entity));
		$this->assertSame('a1', $host->run(['uuid' => 'a1']));
		// toArray() returning null is a miss, as is a row without an identity.
		$this->assertSame('', $host->run(new \stdClass()));
		$this->assertSame('', $host->run(['name' => 'x']));
	}//end testEntityIdOfReadsThroughTheHostsToArray()
}//end class
