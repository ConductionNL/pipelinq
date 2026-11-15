<?php

/**
 * Unit tests for the BrpRetentionDate trait.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Util
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Util;

use DateTimeImmutable;
use OCA\Pipelinq\Util\BrpRetentionDate;
use PHPUnit\Framework\TestCase;

/**
 * A parsable `retentionTo` is a UTC date; absent or unparsable is null.
 *
 * @covers \OCA\Pipelinq\Util\BrpRetentionDate
 */
class BrpRetentionDateTest extends TestCase {

	/**
	 * @return void
	 */
	public function testRetentionDate(): void {
		$host = new class {
			use BrpRetentionDate;

			/**
			 * @param array<string, mixed> $row The row.
			 *
			 * @return DateTimeImmutable|null
			 */
			public function read(array $row): ?DateTimeImmutable {
				return $this->retentionDate(row: $row);
			}
		};

		$this->assertSame('2030-01-02T00:00:00+00:00', $host->read(['retentionTo' => '2030-01-02'])?->format('c'));
		$this->assertNull($host->read([]));
		$this->assertNull($host->read(['retentionTo' => 'not a date']));
	}//end testRetentionDate()
}//end class
