<?php

/**
 * Behavioural tests for the SerialisedRecord trait.
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

use OCA\Pipelinq\Util\SerialisedRecord;
use PHPUnit\Framework\TestCase;

/**
 * Arrays pass, serialisable records are cast, the rest is empty.
 *
 * @covers \OCA\Pipelinq\Util\SerialisedRecord
 */
class SerialisedRecordTest extends TestCase {

	/**
	 * @return void
	 */
	public function testBehaviour(): void {
		$host = new class {
			use SerialisedRecord;

			/**
			 * @param string $method  The private method.
			 * @param mixed  ...$args The arguments.
			 * @return mixed
			 */
			public function call(string $method, mixed ...$args): mixed {
				return $this->$method(...$args);
			}
		};

		$record = new class implements \JsonSerializable {
			/**
			 * @return string
			 */
			public function jsonSerialize(): string {
				return 'v';
			}
		};
		$this->assertSame(['a' => 1], $host->call('recordToArray', ['a' => 1]));
		$this->assertSame(['v'], $host->call('recordToArray', $record));
		$this->assertSame([], $host->call('recordToArray', new \stdClass()));
	}//end testBehaviour()
}//end class
