<?php

/**
 * Unit tests for ContactmomentEntry.
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

use OCA\Pipelinq\Service\ContactmomentEntry;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ContactmomentEntry.
 */
class ContactmomentEntryTest extends TestCase {

	/**
	 * The required fields come back by argument name, in argument order.
	 *
	 * @return void
	 */
	public function testRequiredFieldsInArgumentOrder(): void {
		$entry = new ContactmomentEntry(
			client: 'c1',
			channel: 'telefoon',
			title: 'Call',
			direction: 'inbound',
			outcome: 'afgehandeld',
			notes: null
		);

		$this->assertSame(
			expected: ['client' => 'c1', 'channel' => 'telefoon', 'title' => 'Call', 'direction' => 'inbound'],
			actual: $entry->requiredFields()
		);
		$this->assertSame(expected: 'afgehandeld', actual: $entry->outcome);
		$this->assertNull(actual: $entry->notes);
	}//end testRequiredFieldsInArgumentOrder()
}//end class
