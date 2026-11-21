<?php

/**
 * Behavioural tests for the SerialisedRows trait.
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

use OCA\Pipelinq\Tests\Unit\Service\Support\JsonSerializableRowsTest;
use OCA\Pipelinq\Util\SerialisedRows;
use PHPUnit\Framework\TestCase;

/**
 * Rows with jsonSerialize() are serialised, arrays kept, the rest dropped.
 *
 * @covers \OCA\Pipelinq\Util\SerialisedRows
 */
class SerialisedRowsTest extends TestCase {

	/**
	 * @return void
	 */
	public function testSerialisedRows(): void {
		$host = new class {
			use SerialisedRows;

			/**
			 * @param iterable<mixed> $rows The rows.
			 * @return array<int, array<string, mixed>>
			 */
			public function read(iterable $rows): array {
				return $this->serialisedRows(rows: $rows);
			}
		};

		$this->assertSame([['id' => 'a'], ['id' => 'b'], ['id' => 'duck']], $host->read(rows: JsonSerializableRowsTest::rows()));
	}//end testSerialisedRows()
}//end class
