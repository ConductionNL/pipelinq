<?php

/**
 * Behavioural tests for the FirstValue trait.
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

use OCA\Pipelinq\Service\Support\FirstValue;
use PHPUnit\Framework\TestCase;

/**
 * A list answers its first element; a scalar answers itself.
 *
 * @covers \OCA\Pipelinq\Service\Support\FirstValue
 */
class FirstValueTest extends TestCase {

	/**
	 * @return void
	 */
	public function testBehaviour(): void {
		$host = new class {
			use FirstValue;

			/**
			 * @param string $method  The private method.
			 * @param mixed  ...$args The arguments.
			 * @return mixed
			 */
			public function call(string $method, mixed ...$args): mixed {
				return $this->$method(...$args);
			}
		};

		$this->assertSame('a', $host->call('extractFirstValue', ['a', 'b']));
		$this->assertSame('', $host->call('extractFirstValue', []));
		$this->assertSame('x', $host->call('extractFirstValue', 'x'));
	}//end testBehaviour()
}//end class
