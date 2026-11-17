<?php

/**
 * The web chat channel schema, the chat fields on a conversation and the chatWaiting rule.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/requests-web-chat-and-bot/specs/web-chat-and-handover/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * REQ-WCH-001 and REQ-WCH-004 against the register as the import composes it.
 *
 * @covers \OCA\Pipelinq\Service\ConfigFileLoaderService
 * @uses   \OCA\Pipelinq\Service\ConfigFragmentValue
 */
class WebChatSchemaTest extends TestCase {

	/**
	 * The register as the import composes it.
	 *
	 * @return array<string, mixed> The register file with every fragment merged on.
	 */
	private function register(): array {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 3));
		return (new ConfigFileLoaderService($appManager))->loadConfigurationFile();
	}//end register()

	/**
	 * A web chat channel declares its fields, is off by default, is written by admins only
	 * and is listed in the pipelinq register.
	 *
	 * @return void
	 */
	public function testAWebChatChannelIsADeclarationThatStartsOff(): void {
		$register = $this->register();
		$channel  = $register['components']['schemas']['webChatChannel'] ?? null;
		$this->assertIsArray($channel, 'The webChatChannel schema was not read.');

		$this->assertSame(
			['name', 'allowedOrigins', 'greeting', 'offlineText', 'assistantEnabled', 'waitSeconds', 'showAgentFirstName', 'active', 'channelToken'],
			array_keys($channel['properties'])
		);
		$this->assertFalse($channel['properties']['active']['default']);
		$this->assertFalse($channel['properties']['assistantEnabled']['default']);
		$this->assertFalse($channel['properties']['showAgentFirstName']['default']);
		$this->assertSame(180, $channel['properties']['waitSeconds']['default']);
		$this->assertSame(['name', 'channelToken'], $channel['required']);
		foreach (['create', 'update', 'delete'] as $action) {
			$this->assertSame(['admin'], $channel['authorization'][$action], $action);
		}

		$this->assertContains('webChatChannel', $register['components']['registers']['pipelinq']['schemas']);
	}//end testAWebChatChannelIsADeclarationThatStartsOff()

	/**
	 * A conversation carries the chat fields, a hash of the secret and no address field,
	 * while the channels it already had stay.
	 *
	 * @return void
	 */
	public function testAConversationCarriesTheChatFieldsAndNoAddress(): void {
		$conversation = $this->register()['components']['schemas']['channelConversation'];
		foreach (['webChatChannel', 'visitorSecretHash', 'ticket', 'handedOverAt', 'lastMessageAt', 'assistantToken', 'unsourcedAnswers'] as $field) {
			$this->assertArrayHasKey($field, $conversation['properties'], $field);
		}

		$this->assertContains('chat', $conversation['properties']['channel']['enum']);
		$this->assertArrayNotHasKey('ipAddress', $conversation['properties']);
		$this->assertArrayNotHasKey('visitorSecret', $conversation['properties']);
		$this->assertNotContains('webChatChannel', (array)($conversation['required'] ?? []));
	}//end testAConversationCarriesTheChatFieldsAndNoAddress()

	/**
	 * A chat ticket notifies the newTicket recipients in Nextcloud and as a web push,
	 * and newTicket itself is kept.
	 *
	 * @return void
	 */
	public function testAChatTicketNotifiesTheTeamAtOnce(): void {
		$rules = $this->register()['components']['schemas']['ticket']['x-openregister-notifications'];

		$this->assertArrayHasKey('newTicket', $rules);
		$rule = $rules['chatWaiting'];
		$this->assertSame(['type' => 'created', 'filter' => ['field' => 'channel', 'operator' => 'equals', 'value' => 'chat']], $rule['trigger']);
		$this->assertSame(['nc-notification', 'web-push'], $rule['channels']);
		$this->assertEqualsCanonicalizing($rules['newTicket']['recipients'], $rule['recipients']);
		$this->assertTrue($rule['enabled']);
	}//end testAChatTicketNotifiesTheTeamAtOnce()
}//end class
