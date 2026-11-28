<?php

/**
 * An in-memory web chat store for the web chat tests.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\WebChat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\WebChat;

use OCA\Pipelinq\Service\WebChat\WebChatStore;

/**
 * Keeps channels, conversations and messages in arrays; `readable` names the
 * conversations the current colleague may read.
 */
class FakeWebChatStore extends WebChatStore {

	/**
	 * Channels by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $channels = [];

	/**
	 * Conversations by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $conversations = [];

	/**
	 * Messages, in the order written.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $messages = [];

	/**
	 * Agent profiles.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $profiles = [];

	/**
	 * Conversation ids the colleague may read.
	 *
	 * @var list<string>
	 */
	public array $readable = [];

	/**
	 * A counter for new ids.
	 *
	 * @var int
	 */
	private int $next = 0;

	/**
	 * No container or config needed.
	 */
	public function __construct() {
	}//end __construct()

	/**
	 * The active channel with this token.
	 *
	 * @param string $token The channel token.
	 *
	 * @return array<string, mixed>|null
	 */
	public function activeChannel(string $token): ?array {
		foreach ($this->channels as $channel) {
			if (($channel['channelToken'] ?? null) === $token && ($channel['active'] ?? false) === true) {
				return $channel;
			}
		}

		return null;
	}//end activeChannel()

	/**
	 * The conversation, past the checks.
	 *
	 * @param string $id The conversation id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function conversation(string $id): ?array {
		return ($this->conversations[$id] ?? null);
	}//end conversation()

	/**
	 * The conversation, when the colleague may read it.
	 *
	 * @param string $id The conversation id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function readableConversation(string $id): ?array {
		if (in_array($id, $this->readable, true) === false) {
			return null;
		}

		return ($this->conversations[$id] ?? null);
	}//end readableConversation()

	/**
	 * The channel.
	 *
	 * @param string $id The channel id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function channel(string $id): ?array {
		return ($this->channels[$id] ?? null);
	}//end channel()

	/**
	 * The chat's messages, oldest first.
	 *
	 * @param string $conversationId The conversation id.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function messages(string $conversationId): array {
		return array_values(array_filter($this->messages, static fn (array $m): bool => $m['conversationId'] === $conversationId));
	}//end messages()

	/**
	 * The colleagues' chat profiles.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function agentProfiles(): array {
		return $this->profiles;
	}//end agentProfiles()

	/**
	 * Save a conversation.
	 *
	 * @param array<string, mixed> $conversation The conversation.
	 * @param string|null          $id           Its id, or null for a new one.
	 *
	 * @return array<string, mixed>
	 */
	public function saveConversation(array $conversation, ?string $id=null): array {
		if ($id === null) {
			$id = 'conv-' . (++$this->next);
		}

		$conversation['id']        = $id;
		$this->conversations[$id] = $conversation;
		return $conversation;
	}//end saveConversation()

	/**
	 * Add a message.
	 *
	 * @param array<string, mixed> $message The message.
	 *
	 * @return array<string, mixed>
	 */
	public function addMessage(array $message): array {
		$message['id']    = 'msg-' . (++$this->next);
		$this->messages[] = $message;
		return $message;
	}//end addMessage()
}//end class
