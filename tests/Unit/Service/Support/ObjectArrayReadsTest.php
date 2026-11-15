<?php

/**
 * Behavioural tests for the ObjectArrayReads trait.
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

use OCA\Pipelinq\Service\Support\ObjectArrayReads;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Single reads and lists as arrays.
 *
 * @covers \OCA\Pipelinq\Service\Support\ObjectArrayReads
 */
class ObjectArrayReadsTest extends TestCase {

	/**
	 * @return object A host exposing the trait's steps.
	 */
	private function host(): object {
		return new class {
			use ObjectArrayReads;

			/**
			 * @param mixed $object The object.
			 * @return array<string, mixed>
			 */
			private function toArray(mixed $object): array {
				return ['wrapped' => $object];
			}

			/**
			 * @param string $method  The private method.
			 * @param mixed  ...$args The arguments.
			 * @return mixed
			 */
			public function call(string $method, mixed ...$args): mixed {
				return $this->$method(...$args);
			}
		};
	}//end host()

	/**
	 * @return void
	 */
	public function testFoundAsArray(): void {
		$host = $this->host();
		$this->assertSame(['wrapped' => 'o'], $host->call('foundAsArray', fn () => 'o'));
		$this->assertNull($host->call('foundAsArray', fn () => null));
		$this->assertNull(
			$host->call('foundAsArray', function (): never {
				throw new RuntimeException('down');
			})
		);
	}//end testFoundAsArray()

	/**
	 * @return void
	 */
	public function testAllAsArrays(): void {
		$host = $this->host();
		$this->assertSame([], $host->call('allAsArrays', null));
		$this->assertSame([['wrapped' => 'a'], ['wrapped' => 'b']], $host->call('allAsArrays', ['x' => 'a', 'y' => 'b']));
	}//end testAllAsArrays()
}//end class
