<?php

/**
 * Behavioural tests for the PageCount trait.
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

use OCA\Pipelinq\Service\Support\PageCount;
use PHPUnit\Framework\TestCase;

/**
 * Pages round up; a non-positive total or limit is 0.
 *
 * @covers \OCA\Pipelinq\Service\Support\PageCount
 */
class PageCountTest extends TestCase {

	/**
	 * @return void
	 */
	public function testBehaviour(): void {
		$host = new class {
			use PageCount;

			/**
			 * @param string $method  The private method.
			 * @param mixed  ...$args The arguments.
			 * @return mixed
			 */
			public function call(string $method, mixed ...$args): mixed {
				return $this->$method(...$args);
			}
		};

		$this->assertSame(3, $host->call('computePages', 21, 10));
		$this->assertSame(0, $host->call('computePages', 0, 10));
		$this->assertSame(0, $host->call('computePages', 5, 0));
	}//end testBehaviour()
}//end class
