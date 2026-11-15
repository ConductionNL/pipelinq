<?php

/**
 * Unit tests for UplListEntry.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Upl
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Upl;

use OCA\Pipelinq\Service\Upl\UplListEntry;
use PHPUnit\Framework\TestCase;

/**
 * Tests for UplListEntry.
 */
class UplListEntryTest extends TestCase {

	/**
	 * A row is unchanged only when a stored entry carries the same name and audience.
	 *
	 * @return void
	 */
	public function testIsUnchangedOnlyForTheSameNameAndAudience(): void {
		$row = ['uri' => 'u-1', 'name' => 'Paspoort', 'audience' => ['burger']];

		$this->assertFalse(condition: (new UplListEntry(row: $row, existing: null))->isUnchanged());
		$this->assertTrue(condition: (new UplListEntry(row: $row, existing: ['id' => 'x'] + $row))->isUnchanged());
		$this->assertFalse(condition: (new UplListEntry(row: $row, existing: ['name' => 'Rijbewijs', 'audience' => ['burger']]))->isUnchanged());
		$this->assertFalse(condition: (new UplListEntry(row: $row, existing: ['name' => 'Paspoort', 'audience' => []]))->isUnchanged());
	}//end testIsUnchangedOnlyForTheSameNameAndAudience()
}//end class
