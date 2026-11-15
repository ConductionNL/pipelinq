<?php

/**
 * Unit tests for MessageRoute: the context a channel adapter gets.
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

use OCA\Pipelinq\Service\Messaging\MessageRoute;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Where a message goes and what the adapter is told about it.
 */
#[CoversClass(MessageRoute::class)]
class MessageRouteTest extends TestCase {

	/**
	 * The explicit client wins over the contact's, and a reply carries what it answers.
	 *
	 * @return void
	 */
	public function testAnExplicitClientAndAReply(): void {
		$route = new MessageRoute(contact: ['client' => 'c-contact'], providerHint: 'twilio', clientId: 'c-explicit', inReplyTo: 'msg-7');

		$this->assertSame(
			['agent' => 'anouk', 'clientId' => 'c-explicit', 'inReplyTo' => 'msg-7'],
			$route->adapterContext(agent: 'anouk')
		);
	}//end testAnExplicitClientAndAReply()

	/**
	 * Without a client id the contact's client is used, and a new message has no inReplyTo.
	 *
	 * @return void
	 */
	public function testFallsBackToTheContactsClient(): void {
		$route = new MessageRoute(contact: ['client' => 42]);

		$this->assertSame(['agent' => 'jan', 'clientId' => '42'], $route->adapterContext(agent: 'jan'));
		$this->assertNull($route->providerHint);
	}//end testFallsBackToTheContactsClient()

	/**
	 * A contact without a client gives an empty client id.
	 *
	 * @return void
	 */
	public function testAContactWithoutAClient(): void {
		$this->assertSame(['agent' => '', 'clientId' => ''], (new MessageRoute(contact: []))->adapterContext(agent: ''));
	}//end testAContactWithoutAClient()
}//end class
