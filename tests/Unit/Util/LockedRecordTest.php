<?php

/**
 * A locked record is recognised in each form a background run reads it in.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Util
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/platform-record-lock/specs/record-locking/spec.md#requirement-a-user-can-lock-a-client-a-finished-lead-or-a-finished-ticket-req-prl-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Util;

use OCA\Pipelinq\Util\LockedRecord;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Util\LockedRecord
 */
class LockedRecordTest extends TestCase {

	/**
	 * A serialised row is locked only when `@self.frozen` holds the lock.
	 *
	 * @return void
	 */
	public function testASerialisedRowIsLockedByItsFreezeMetadata(): void {
		$lock = ['by' => 'admin', 'at' => '2026-11-01T00:00:00+00:00'];

		$this->assertTrue(LockedRecord::isLocked(record: ['id' => 'c-1', '@self' => ['frozen' => $lock]]));
		$this->assertFalse(LockedRecord::isLocked(record: ['id' => 'c-1', '@self' => ['frozen' => null]]));
		$this->assertFalse(LockedRecord::isLocked(record: ['id' => 'c-1', '@self' => ['frozen' => []]]));
		$this->assertFalse(LockedRecord::isLocked(record: ['id' => 'c-1', '@self' => ['frozen' => 'yes']]));
		$this->assertFalse(LockedRecord::isLocked(record: ['id' => 'c-1', '@self' => 'broken']));
		$this->assertFalse(LockedRecord::isLocked(record: ['id' => 'c-1', 'frozen' => $lock]), 'a data field named frozen is not the lock');
		$this->assertFalse(LockedRecord::isLocked(record: ['id' => 'c-1']));
	}//end testASerialisedRowIsLockedByItsFreezeMetadata()

	/**
	 * An object entity answers through its own isFrozen().
	 *
	 * @return void
	 */
	public function testAnEntityAnswersThroughIsFrozen(): void {
		$locked = new class {
			/**
			 * @return bool
			 */
			public function isFrozen(): bool {
				return true;
			}
		};
		$open = new class {
			/**
			 * @return bool
			 */
			public function isFrozen(): bool {
				return false;
			}
		};

		$this->assertTrue(LockedRecord::isLocked(record: $locked));
		$this->assertFalse(LockedRecord::isLocked(record: $open));
		$this->assertFalse(LockedRecord::isLocked(record: new \stdClass()));
		$this->assertFalse(LockedRecord::isLocked(record: null));
		$this->assertFalse(LockedRecord::isLocked(record: 'c-1'));
	}//end testAnEntityAnswersThroughIsFrozen()
}//end class
