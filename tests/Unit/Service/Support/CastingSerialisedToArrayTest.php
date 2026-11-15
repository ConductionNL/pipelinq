<?php

/**
 * Behavioural tests for the CastingSerialisedToArray trait.
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

use OCA\Pipelinq\Service\Support\CastingSerialisedToArray;
use PHPUnit\Framework\TestCase;

/**
 * Arrays, serialisable entities, plain objects, scalars and result lists.
 *
 * @covers \OCA\Pipelinq\Service\Support\CastingSerialisedToArray
 */
class CastingSerialisedToArrayTest extends TestCase {

	/**
	 * @return void
	 */
	public function testToArrayAndResults(): void {
		$host = new class {
			use CastingSerialisedToArray;

			/**
			 * @param string $method  The private method.
			 * @param mixed  ...$args The arguments.
			 * @return mixed
			 */
			public function call(string $method, mixed ...$args): mixed {
				return $this->$method(...$args);
			}
		};
		$entity = new class implements \JsonSerializable {
			/**
			 * @return array<string, string>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'e1'];
			}
		};
		$plain = new \stdClass();
		$plain->id = 'p1';

		$this->assertSame(['a' => 1], $host->call('toArray', ['a' => 1]));
		$this->assertSame(['id' => 'e1'], $host->call('toArray', $entity));
		$this->assertSame(['id' => 'p1'], $host->call('toArray', $plain));
		$this->assertSame([], $host->call('toArray', 'scalar'));
		$this->assertSame([['id' => 'e1'], ['id' => 'p1']], $host->call('resultsToArrays', [$entity, $plain]));
	}//end testToArrayAndResults()
}//end class
