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
 * @spec openspec/specs/web-chat-and-handover/spec.md
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
		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 3));
		return (new ConfigFileLoaderService(appManager: $appManager))->loadConfigurationFile();
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
		$this->assertIsArray(actual: $channel, message: 'The webChatChannel schema was not read.');

		$this->assertSame(
			expected: [
				'name',
				'allowedOrigins',
				'greeting',
				'offlineText',
				'assistantEnabled',
				'waitSeconds',
				'showAgentFirstName',
				'active',
				'channelToken',
			],
			actual: array_keys($channel['properties'])
		);
		$this->assertFalse(condition: $channel['properties']['active']['default']);
		$this->assertFalse(condition: $channel['properties']['assistantEnabled']['default']);
		$this->assertFalse(condition: $channel['properties']['showAgentFirstName']['default']);
		$this->assertSame(expected: 180, actual: $channel['properties']['waitSeconds']['default']);
		$this->assertSame(expected: ['name', 'channelToken'], actual: $channel['required']);
		foreach (['create', 'update', 'delete'] as $action) {
			$this->assertSame(expected: ['admin'], actual: $channel['authorization'][$action], message: $action);
		}

		$this->assertContains(needle: 'webChatChannel', haystack: $register['components']['registers']['pipelinq']['schemas']);
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
			$this->assertArrayHasKey(key: $field, array: $conversation['properties'], message: $field);
		}

		$this->assertContains(needle: 'chat', haystack: $conversation['properties']['channel']['enum']);
		$this->assertArrayNotHasKey(key: 'ipAddress', array: $conversation['properties']);
		$this->assertArrayNotHasKey(key: 'visitorSecret', array: $conversation['properties']);
		$this->assertNotContains(needle: 'webChatChannel', haystack: (array)($conversation['required'] ?? []));
	}//end testAConversationCarriesTheChatFieldsAndNoAddress()

	/**
	 * A chat ticket notifies the newTicket recipients in Nextcloud and as a web push,
	 * and newTicket itself is kept.
	 *
	 * @return void
	 */
	public function testAChatTicketNotifiesTheTeamAtOnce(): void {
		$rules = $this->register()['components']['schemas']['ticket']['x-openregister-notifications'];

		$this->assertArrayHasKey(key: 'newTicket', array: $rules);
		$rule = $rules['chatWaiting'];
		$this->assertSame(
			expected: ['type' => 'created', 'filter' => ['field' => 'channel', 'operator' => 'equals', 'value' => 'chat']],
			actual: $rule['trigger'],
		);
		$this->assertSame(expected: ['nc-notification', 'web-push'], actual: $rule['channels']);
		$this->assertEqualsCanonicalizing(expected: $rules['newTicket']['recipients'], actual: $rule['recipients']);
		$this->assertTrue(condition: $rule['enabled']);
	}//end testAChatTicketNotifiesTheTeamAtOnce()
}//end class
