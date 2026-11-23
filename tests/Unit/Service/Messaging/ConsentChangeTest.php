<?php

/**
 * Unit tests for ConsentChange: one consent change of the send surface.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Messaging
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Messaging;

use OCA\Pipelinq\Service\Messaging\ConsentChange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What a consent change carries and how its evidence is attributed.
 */
#[CoversClass(ConsentChange::class)]
class ConsentChangeTest extends TestCase {

	/**
	 * Attribution appends the user to the evidence and keeps every other field.
	 *
	 * @return void
	 */
	public function testAttributionKeepsTheOtherFields(): void {
		$change = new ConsentChange(contactId: 'c-1', channel: 'sms', action: 'opt-in', source: 'manual', evidence: 'phone call', legalBasis: 'consent');

		$attributed = $change->attributedTo(userId: 'anouk');

		$this->assertSame(expected: 'phone call (recorded by anouk)', actual: $attributed->evidence);
		$this->assertSame(
			expected: ['c-1', 'sms', 'opt-in', 'manual', 'consent'],
			actual: [$attributed->contactId, $attributed->channel, $attributed->action, $attributed->source, $attributed->legalBasis]
		);
		$this->assertSame(expected: 'phone call', actual: $change->evidence);
	}//end testAttributionKeepsTheOtherFields()
}//end class
