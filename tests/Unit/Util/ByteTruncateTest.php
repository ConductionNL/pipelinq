<?php

/**
 * Behavioural tests for the ByteTruncate trait.
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

use OCA\Pipelinq\Util\ByteTruncate;
use PHPUnit\Framework\TestCase;

/**
 * Short strings pass; long ones keep their first max bytes.
 *
 * @covers \OCA\Pipelinq\Util\ByteTruncate
 */
class ByteTruncateTest extends TestCase {

	/**
	 * @return void
	 */
	public function testBehaviour(): void {
		$host = new class {
			use ByteTruncate;

			/**
			 * @param string $method  The private method.
			 * @param mixed  ...$args The arguments.
			 * @return mixed
			 */
			public function call(string $method, mixed ...$args): mixed {
				return $this->$method(...$args);
			}
		};

		$this->assertSame('abc', $host->call('truncate', 'abc', 5));
		$this->assertSame('ab', $host->call('truncate', 'abc', 2));
	}//end testBehaviour()
}//end class
