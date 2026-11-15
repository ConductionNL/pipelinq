<?php

/**
 * Behavioural tests for the CastSerialisedToArray trait.
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
use OCA\Pipelinq\Util\CastSerialisedToArray;
use PHPUnit\Framework\TestCase;

/**
 * Arrays pass, jsonSerialize() then getObject() results are cast, the rest is [].
 *
 * @covers \OCA\Pipelinq\Util\CastSerialisedToArray
 */
class CastSerialisedToArrayTest extends TestCase {

	/**
	 * @return void
	 */
	public function testShapes(): void {
		$host = new class {
			use CastSerialisedToArray;

			/**
			 * @param mixed $value The value.
			 * @return array<mixed>
			 */
			public function read(mixed $value): array {
				return $this->toArray(value: $value);
			}
		};

		$serialisable = new class implements JsonSerializable {
			/**
			 * @return string
			 */
			public function jsonSerialize(): string {
				return 'x';
			}
		};
		$entity = new class {
			/**
			 * @return array<string, string>
			 */
			public function getObject(): array {
				return ['id' => 'e1'];
			}
		};

		$this->assertSame(['id' => 'a'], $host->read(value: ['id' => 'a']));
		$this->assertSame(['x'], $host->read(value: $serialisable));
		$this->assertSame(['id' => 'e1'], $host->read(value: $entity));
		$this->assertSame([], $host->read(value: new \stdClass()));
		$this->assertSame([], $host->read(value: 'text'));
	}//end testShapes()
}//end class
