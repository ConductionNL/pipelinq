<?php

/**
 * Unit tests for CompletedInteraction.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\CompletedInteraction;
use PHPUnit\Framework\TestCase;

/**
 * CompletedInteraction matches rule triggers on type, status and channel.
 */
class CompletedInteractionTest extends TestCase {

	/**
	 * A trigger naming the same type and status matches; an empty channel filter matches any channel.
	 *
	 * @return void
	 */
	public function testMatchesTypeStatusAndAnyChannel(): void {
		$interaction = new CompletedInteraction(entityType: 'ticket', status: 'closed', channel: 'phone');

		$this->assertTrue($interaction->matches(trigger: ['entityType' => ' ticket ', 'statusEquals' => 'closed']));
		$this->assertTrue($interaction->matches(trigger: ['entityType' => 'ticket', 'statusEquals' => 'closed', 'channelEquals' => 'phone']));
	}//end testMatchesTypeStatusAndAnyChannel()

	/**
	 * A different type, status or channel does not match.
	 *
	 * @return void
	 */
	public function testRejectsAnyDifference(): void {
		$interaction = new CompletedInteraction(entityType: 'ticket', status: 'closed', channel: 'phone');

		$this->assertFalse($interaction->matches(trigger: ['entityType' => 'lead', 'statusEquals' => 'closed']));
		$this->assertFalse($interaction->matches(trigger: ['entityType' => 'ticket', 'statusEquals' => 'open']));
		$this->assertFalse($interaction->matches(trigger: ['entityType' => 'ticket', 'statusEquals' => 'closed', 'channelEquals' => 'email']));
		$this->assertFalse($interaction->matches(trigger: []));
	}//end testRejectsAnyDifference()
}//end class
