<?php

/**
 * Behavioural tests for the SerialisedCast trait.
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

use OCA\Pipelinq\Service\Support\SerialisedCast;
use PHPUnit\Framework\TestCase;

/**
 * A serialisable row is cast from its serialisation; others as they are.
 *
 * @covers \OCA\Pipelinq\Service\Support\SerialisedCast
 */
class SerialisedCastTest extends TestCase {

	/**
	 * @return void
	 */
	public function testBehaviour(): void {
		$host = new class {
			use SerialisedCast;

			/**
			 * @param string $method  The private method.
			 * @param mixed  ...$args The arguments.
			 * @return mixed
			 */
			public function call(string $method, mixed ...$args): mixed {
				return $this->$method(...$args);
			}
		};

		$row = new class implements \JsonSerializable {
			/**
			 * @return string
			 */
			public function jsonSerialize(): string {
				return 'v';
			}
		};
		$this->assertSame(['v'], $host->call('asArray', $row));
		$this->assertSame(['a' => 1], $host->call('asArray', ['a' => 1]));
		$this->assertSame(['p' => 2], $host->call('asArray', (object)['p' => 2]));
	}//end testBehaviour()
}//end class
