<?php

/**
 * Behavioural tests for the NonEmptyString trait.
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

use OCA\Pipelinq\Service\Support\NonEmptyString;
use PHPUnit\Framework\TestCase;

/**
 * Null, non-scalar and empty values are null; scalars are strings.
 *
 * @covers \OCA\Pipelinq\Service\Support\NonEmptyString
 */
class NonEmptyStringTest extends TestCase {

	/**
	 * @return void
	 */
	public function testBehaviour(): void {
		$host = new class {
			use NonEmptyString;

			/**
			 * @param string $method  The private method.
			 * @param mixed  ...$args The arguments.
			 * @return mixed
			 */
			public function call(string $method, mixed ...$args): mixed {
				return $this->$method(...$args);
			}
		};

		$this->assertNull($host->call('stringOrNull', null));
		$this->assertNull($host->call('stringOrNull', ''));
		$this->assertNull($host->call('stringOrNull', ['x']));
		$this->assertSame('7', $host->call('stringOrNull', 7));
		$this->assertSame('a', $host->call('stringOrNull', 'a'));
	}//end testBehaviour()
}//end class
