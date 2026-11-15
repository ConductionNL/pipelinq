<?php

/**
 * Behavioural tests for the SchemaRowArrays trait.
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

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Matching rows come back as arrays; no service or a failed read is [].
 *
 * @covers \OCA\Pipelinq\Util\SchemaRowArrays
 */
class SchemaRowArraysTest extends TestCase {

	/**
	 * @return void
	 */
	public function testSchemaRowArrays(): void {
		$logger = $this->createStub(LoggerInterface::class);
		$rows = SchemaRowQueryTest::store([['id' => 'a'], (object)['id' => 'b']]);

		$this->assertSame([['id' => 'a'], ['id' => 'b']], SchemaRowQueryTest::host($rows, $logger)->arrays([]));
		$this->assertSame([], SchemaRowQueryTest::host(null, $logger)->arrays([]));
		$this->assertSame([], SchemaRowQueryTest::host(SchemaRowQueryTest::store(null), $logger)->arrays([]));
		$this->assertSame([], SchemaRowQueryTest::host(SchemaRowQueryTest::store(new \RuntimeException('down')), $logger)->arrays([]));
	}//end testSchemaRowArrays()
}//end class
