<?php

/**
 * Tests for the ResultToArray trait.
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
use OCA\Pipelinq\Util\ResultToArray;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * An entity becomes its jsonSerialize() array, an array stays, anything else is empty.
 *
 * @covers \OCA\Pipelinq\Util\ResultToArray
 */
class ResultToArrayTest extends TestCase {

	/**
	 * Run the trait's helper on one value.
	 *
	 * @param mixed $result The value.
	 *
	 * @return array<mixed>
	 */
	private function toArray(mixed $result): array {
		$subject = new class {
			use ResultToArray;

			/**
			 * Expose the helper.
			 *
			 * @param mixed $result The value.
			 *
			 * @return array<mixed>
			 */
			public function run(mixed $result): array {
				return $this->serializeResult(result: $result);
			}//end run()
		};

		return $subject->run(result: $result);
	}//end toArray()

	/**
	 * An entity is serialised through jsonSerialize().
	 *
	 * @return void
	 */
	public function testAnEntityBecomesItsSerialisedArray(): void {
		$entity = new class implements JsonSerializable {
			/**
			 * The serialised form.
			 *
			 * @return array<string, string>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'o-1', 'name' => 'Acme'];
			}//end jsonSerialize()
		};

		$this->assertSame(['id' => 'o-1', 'name' => 'Acme'], $this->toArray(result: $entity));
	}//end testAnEntityBecomesItsSerialisedArray()

	/**
	 * An array is returned as it is.
	 *
	 * @return void
	 */
	public function testAnArrayStaysAsItIs(): void {
		$this->assertSame(['id' => 'o-2', 3 => 'x'], $this->toArray(result: ['id' => 'o-2', 3 => 'x']));
	}//end testAnArrayStaysAsItIs()

	/**
	 * Null, a scalar or an object without jsonSerialize() becomes an empty array.
	 *
	 * @return void
	 */
	public function testAnythingElseBecomesEmpty(): void {
		$this->assertSame([], $this->toArray(result: null));
		$this->assertSame([], $this->toArray(result: 'o-3'));
		$this->assertSame([], $this->toArray(result: new stdClass()));
	}//end testAnythingElseBecomesEmpty()
}//end class
