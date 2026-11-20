<?php

/**
 * Behavioural tests for the JsonSerializableToArray trait.
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
use OCA\Pipelinq\Util\JsonSerializableToArray;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Arrays pass, JsonSerializable rows serialise, the rest is null.
 *
 * @covers \OCA\Pipelinq\Util\JsonSerializableToArray
 */
class JsonSerializableToArrayTest extends TestCase {

	/**
	 * @return void
	 */
	public function testRowShapes(): void {
		$host = new class {
			use JsonSerializableToArray;

			/**
			 * @param mixed $row The row.
			 * @return array<string, mixed>|null
			 */
			public function read(mixed $row): ?array {
				return $this->toArray(row: $row);
			}
		};

		$entity = new class implements JsonSerializable {
			/**
			 * @return array<string, string>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'e1'];
			}
		};
		$scalarEntity = new class implements JsonSerializable {
			/**
			 * @return string
			 */
			public function jsonSerialize(): string {
				return 'x';
			}
		};

		$this->assertSame(['id' => 'a1'], $host->read(row: ['id' => 'a1']));
		$this->assertSame(['id' => 'e1'], $host->read(row: $entity));
		$this->assertNull($host->read(row: $scalarEntity));
		$this->assertNull($host->read(row: new stdClass()));
		$this->assertNull($host->read(row: 'text'));
	}//end testRowShapes()
}//end class
