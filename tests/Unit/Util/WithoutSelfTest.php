<?php

/**
 * Behavioural tests for the WithoutSelf trait.
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

use OCA\Pipelinq\Util\WithoutSelf;
use PHPUnit\Framework\TestCase;

/**
 * The @self block is dropped; everything else stays.
 *
 * @covers \OCA\Pipelinq\Util\WithoutSelf
 */
class WithoutSelfTest extends TestCase {

	/**
	 * @return void
	 */
	public function testBehaviour(): void {
		$host = new class {
			use WithoutSelf;

			/**
			 * @param string $method  The private method.
			 * @param mixed  ...$args The arguments.
			 * @return mixed
			 */
			public function call(string $method, mixed ...$args): mixed {
				return $this->$method(...$args);
			}
		};

		$this->assertSame(['id' => 'x'], $host->call('stripSelf', ['@self' => ['a' => 1], 'id' => 'x']));
		$this->assertSame(['id' => 'x'], $host->call('stripSelf', ['id' => 'x']));
	}//end testBehaviour()
}//end class
