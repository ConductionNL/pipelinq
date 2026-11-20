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
	 * {@inheritDoc}
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
	 * {@inheritDoc}
	 */
	public function conversation(string $id): ?array {
		return ($this->conversations[$id] ?? null);
	}//end conversation()

	/**
	 * {@inheritDoc}
	 */
	public function readableConversation(string $id): ?array {
		if (in_array($id, $this->readable, true) === false) {
			return null;
		}

		return ($this->conversations[$id] ?? null);
	}//end readableConversation()

	/**
	 * {@inheritDoc}
	 */
	public function channel(string $id): ?array {
		return ($this->channels[$id] ?? null);
	}//end channel()

	/**
	 * {@inheritDoc}
	 */
	public function messages(string $conversationId): array {
		return array_values(array_filter($this->messages, static fn (array $m): bool => $m['conversationId'] === $conversationId));
	}//end messages()

	/**
	 * {@inheritDoc}
	 */
	public function agentProfiles(): array {
		return $this->profiles;
	}//end agentProfiles()

	/**
	 * {@inheritDoc}
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
	 * {@inheritDoc}
	 */
	public function addMessage(array $message): array {
		$message['id']    = 'msg-' . (++$this->next);
		$this->messages[] = $message;
		return $message;
	}//end addMessage()
}//end class
