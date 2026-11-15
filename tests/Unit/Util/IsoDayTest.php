<?php

/**
 * Behavioural tests for the IsoDay trait.
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

use OCA\Pipelinq\Util\IsoDay;
use PHPUnit\Framework\TestCase;

/**
 * A valid Y-m-d is midnight UTC; malformed or impossible dates are null.
 *
 * @covers \OCA\Pipelinq\Util\IsoDay
 */
class IsoDayTest extends TestCase {

	/**
	 * @return void
	 */
	public function testBehaviour(): void {
		$host = new class {
			use IsoDay;

			/**
			 * @param string $method  The private method.
			 * @param mixed  ...$args The arguments.
			 * @return mixed
			 */
			public function call(string $method, mixed ...$args): mixed {
				return $this->$method(...$args);
			}
		};

		$this->assertSame('2026-02-28T00:00:00+00:00', $host->call('parseDay', ' 2026-02-28 ')->format('c'));
		$this->assertNull($host->call('parseDay', '2026-02-30'));
		$this->assertNull($host->call('parseDay', '28-02-2026'));
		$this->assertNull($host->call('parseDay', null));
	}//end testBehaviour()
}//end class
