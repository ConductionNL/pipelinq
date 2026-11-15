<?php

/**
 * Behavioural tests for the WritableAddressBook trait.
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

use OCA\Pipelinq\Util\WritableAddressBook;
use OCP\IAddressBook;
use PHPUnit\Framework\TestCase;

/**
 * System books are never writable; others when they grant updates.
 *
 * @covers \OCA\Pipelinq\Util\WritableAddressBook
 */
class WritableAddressBookTest extends TestCase {

	/**
	 * @return void
	 */
	public function testBehaviour(): void {
		$host = new class {
			use WritableAddressBook;

			/**
			 * @param string $method  The private method.
			 * @param mixed  ...$args The arguments.
			 * @return mixed
			 */
			public function call(string $method, mixed ...$args): mixed {
				return $this->$method(...$args);
			}
		};

		foreach ([[true, 31, false], [false, 31, true], [false, 1, false]] as [$isSystem, $permissions, $expected]) {
			$book = $this->createStub(IAddressBook::class);
			$book->method('isSystemAddressBook')->willReturn($isSystem);
			$book->method('getPermissions')->willReturn($permissions);
			$this->assertSame($expected, $host->call('isWritable', $book));
		}
	}//end testBehaviour()
}//end class
