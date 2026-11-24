<?php

/**
 * Behavioural tests for the CentsAsDecimal trait.
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

use OCA\Pipelinq\Service\Support\CentsAsDecimal;
use PHPUnit\Framework\TestCase;

/**
 * Cents format as a signed two-decimal string.
 *
 * @covers \OCA\Pipelinq\Service\Support\CentsAsDecimal
 */
class CentsAsDecimalTest extends TestCase {

	/**
	 * @return void
	 */
	public function testBehaviour(): void {
		$host = new class {
			use CentsAsDecimal;

			/**
			 * @param string $method  The private method.
			 * @param mixed  ...$args The arguments.
			 * @return mixed
			 */
			public function call(string $method, mixed ...$args): mixed {
				return $this->$method(...$args);
			}
		};

		$this->assertSame('10.50', $host->call('centsToDecimal', 1050));
		$this->assertSame('-0.05', $host->call('centsToDecimal', -5));
		$this->assertSame('0.00', $host->call('centsToDecimal', 0));
	}//end testBehaviour()
}//end class
