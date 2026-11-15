<?php

/**
 * Unit tests for the LoggedFindAsArray trait.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Support
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Support;

use OCA\Pipelinq\Service\Support\LoggedFindAsArray;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * A found object is converted; a miss is null; a failure is reported and null.
 *
 * @covers \OCA\Pipelinq\Service\Support\LoggedFindAsArray
 */
class LoggedFindAsArrayTest extends TestCase {

	/**
	 * @return void
	 */
	public function testFoundMissAndFailure(): void {
		$host = new class {
			use LoggedFindAsArray;

			/**
			 * @var array<int, string>
			 */
			public array $failures = [];

			/**
			 * @param mixed $value The value.
			 *
			 * @return array<string, mixed>
			 */
			private function toArray(mixed $value): array {
				return ['converted' => $value];
			}

			/**
			 * @param \Closure $find The read.
			 *
			 * @return array<string, mixed>|null
			 */
			public function read(\Closure $find): ?array {
				return $this->loggedFindAsArray(
					find: $find,
					onFailure: function (Throwable $e): void {
						$this->failures[] = $e->getMessage();
					}
				);
			}
		};

		$this->assertSame(['converted' => 'row'], $host->read(fn (): string => 'row'));
		$this->assertNull($host->read(fn (): mixed => null));
		$this->assertNull(
			$host->read(
				function (): mixed {
					throw new RuntimeException('down');
				}
			)
		);
		$this->assertSame(['down'], $host->failures);
	}//end testFoundMissAndFailure()
}//end class
