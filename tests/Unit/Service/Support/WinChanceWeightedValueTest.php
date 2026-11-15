<?php

/**
 * Behavioural tests for the WinChanceWeightedValue trait.
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

use OCA\Pipelinq\Service\Support\WinChanceWeightedValue;
use PHPUnit\Framework\TestCase;

/**
 * The value is weighted by the clamped score; no score weighs 0.
 *
 * @covers \OCA\Pipelinq\Service\Support\WinChanceWeightedValue
 */
class WinChanceWeightedValueTest extends TestCase {

	/**
	 * @return void
	 */
	public function testBehaviour(): void {
		$host = new class {
			use WinChanceWeightedValue;

			/**
			 * @param string $method  The private method.
			 * @param mixed  ...$args The arguments.
			 * @return mixed
			 */
			public function call(string $method, mixed ...$args): mixed {
				return $this->$method(...$args);
			}
		};

		$this->assertSame(50.0, $host->call('winChanceWeightedValue', ['value' => 100, 'qualificationScore' => 50]));
		$this->assertSame(100.0, $host->call('winChanceWeightedValue', ['value' => 100, 'qualificationScore' => 150]));
		$this->assertSame(0.0, $host->call('winChanceWeightedValue', ['value' => 100, 'qualificationScore' => 'n/a']));
	}//end testBehaviour()
}//end class
