<?php

/**
 * Behavioural tests for the JsonSerializableRows trait.
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

use JsonSerializable;
use OCA\Pipelinq\Util\JsonSerializableRows;
use PHPUnit\Framework\TestCase;

/**
 * Array and JsonSerializable rows are kept as arrays; the rest is dropped.
 *
 * @covers \OCA\Pipelinq\Util\JsonSerializableRows
 */
class JsonSerializableRowsTest extends TestCase {

	/**
	 * Rows of every shape, with a duck-typed serialisable that is not JsonSerializable.
	 *
	 * @return array<int, mixed>
	 */
	public static function rows(): array {
		return [
			['id' => 'a'],
			new class implements JsonSerializable {
				/**
				 * @return array<string, string>
				 */
				public function jsonSerialize(): array {
					return ['id' => 'b'];
				}
			},
			new class implements JsonSerializable {
				/**
				 * @return string
				 */
				public function jsonSerialize(): string {
					return 'scalar';
				}
			},
			new class {
				/**
				 * @return array<string, string>
				 */
				public function jsonSerialize(): array {
					return ['id' => 'duck'];
				}
			},
			'text',
		];
	}//end rows()

	/**
	 * @return void
	 */
	public function testPlainRows(): void {
		$host = new class {
			use JsonSerializableRows;

			/**
			 * @param iterable<mixed> $rows The rows.
			 * @return array<int, array<string, mixed>>
			 */
			public function read(iterable $rows): array {
				return $this->plainRows(rows: $rows);
			}
		};

		$this->assertSame([['id' => 'a'], ['id' => 'b']], $host->read(rows: self::rows()));
	}//end testPlainRows()
}//end class
