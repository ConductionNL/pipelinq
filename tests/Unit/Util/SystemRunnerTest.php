<?php

/**
 * Behavioural tests for the SystemRunner trait.
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

use OCA\Pipelinq\Util\SystemRunner;
use PHPUnit\Framework\TestCase;

/**
 * The operation runs through runAsSystem() when the service has it.
 *
 * @covers \OCA\Pipelinq\Util\SystemRunner
 */
class SystemRunnerTest extends TestCase {

	/**
	 * @return void
	 */
	public function testBehaviour(): void {
		$host = new class {
			use SystemRunner;

			/**
			 * @param string $method  The private method.
			 * @param mixed  ...$args The arguments.
			 * @return mixed
			 */
			public function call(string $method, mixed ...$args): mixed {
				return $this->$method(...$args);
			}
		};

		$plain = new \stdClass();
		$this->assertSame('direct', $host->call('execAsSystem', $plain, static fn (): string => 'direct'));
		$system = new class {
			/**
			 * @param callable $operation The operation.
			 * @return string
			 */
			public function runAsSystem(callable $operation): string {
				return 'system:'.$operation();
			}
		};
		$this->assertSame('system:op', $host->call('execAsSystem', $system, static fn (): string => 'op'));
	}//end testBehaviour()
}//end class
