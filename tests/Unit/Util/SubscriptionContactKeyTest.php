<?php

/**
 * Behavioural tests for the SubscriptionContactKey trait.
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

use OCA\Pipelinq\Util\SubscriptionContactKey;
use PHPUnit\Framework\TestCase;

/**
 * The contact id wins; the email stands in without one.
 *
 * @covers \OCA\Pipelinq\Util\SubscriptionContactKey
 */
class SubscriptionContactKeyTest extends TestCase {

	/**
	 * @return void
	 */
	public function testBehaviour(): void {
		$host = new class {
			use SubscriptionContactKey;

			/**
			 * @param string $method  The private method.
			 * @param mixed  ...$args The arguments.
			 * @return mixed
			 */
			public function call(string $method, mixed ...$args): mixed {
				return $this->$method(...$args);
			}
		};

		$this->assertSame('c1', $host->call('contactKeyFor', ['contactId' => 'c1', 'email' => 'a@b.nl']));
		$this->assertSame('a@b.nl', $host->call('contactKeyFor', ['contactId' => '', 'email' => 'a@b.nl']));
		$this->assertSame('', $host->call('contactKeyFor', []));
	}//end testBehaviour()
}//end class
