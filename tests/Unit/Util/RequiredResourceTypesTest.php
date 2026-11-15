<?php

/**
 * Behavioural tests for the RequiredResourceTypes trait.
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

use OCA\Pipelinq\Util\RequiredResourceTypes;
use PHPUnit\Framework\TestCase;

/**
 * A listed type set is returned; a missing or malformed one is empty.
 *
 * @covers \OCA\Pipelinq\Util\RequiredResourceTypes
 */
class RequiredResourceTypesTest extends TestCase {

	/**
	 * @return void
	 */
	public function testBehaviour(): void {
		$host = new class {
			use RequiredResourceTypes;

			/**
			 * @param string $method  The private method.
			 * @param mixed  ...$args The arguments.
			 * @return mixed
			 */
			public function call(string $method, mixed ...$args): mixed {
				return $this->$method(...$args);
			}
		};

		$this->assertSame(['room'], $host->call('requiredResourceTypes', ['requiredResourceTypes' => ['room']]));
		$this->assertSame([], $host->call('requiredResourceTypes', ['requiredResourceTypes' => 'room']));
		$this->assertSame([], $host->call('requiredResourceTypes', []));
	}//end testBehaviour()
}//end class
