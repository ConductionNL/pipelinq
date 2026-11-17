<?php

/**
 * Unit tests for VcardWrite.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\VcardWrite;
use PHPUnit\Framework\TestCase;

/**
 * Tests for VcardWrite.
 */
class VcardWriteTest extends TestCase {

	/**
	 * Only a non-empty UID links the write to a card.
	 *
	 * @return void
	 */
	public function testIsLinkedOnlyForANonEmptyUid(): void {
		$this->assertTrue(condition: (new VcardWrite(properties: ['FN' => 'A'], existingUid: 'uid-1'))->isLinked());
		$this->assertFalse(condition: (new VcardWrite(properties: [], existingUid: ''))->isLinked());
		$this->assertFalse(condition: (new VcardWrite(properties: [], existingUid: null))->isLinked());
	}//end testIsLinkedOnlyForANonEmptyUid()

	/**
	 * The properties are kept as given.
	 *
	 * @return void
	 */
	public function testKeepsTheProperties(): void {
		$write = new VcardWrite(properties: ['FN' => 'A', 'EMAIL' => 'a@example.org'], existingUid: null);

		$this->assertSame(expected: ['FN' => 'A', 'EMAIL' => 'a@example.org'], actual: $write->properties);
	}//end testKeepsTheProperties()
}//end class
