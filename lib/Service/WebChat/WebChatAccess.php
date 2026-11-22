<?php

/**
 * Who may reach a website chat: the visitor by the chat secret, the colleague through OpenRegister's checks.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\WebChat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/requests-web-chat-and-bot/specs/web-chat-and-handover/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\WebChat;

use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Resolves the channel and the conversation a caller may reach, or refuses.
 *
 * A visitor proves a chat by its secret, of which only a hash is stored. A
 * colleague reads a conversation through OpenRegister's checks: one they may
 * not read is not there.
 */
class WebChatAccess {

	/**
	 * Constructor.
	 *
	 * @param WebChatStore    $store  Where the chat is kept.
	 * @param LoggerInterface $logger Where an unreadable channel list is logged.
	 */
	public function __construct(
		private readonly WebChatStore $store,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The hash of a chat secret, the only form in which it is stored.
	 *
	 * @param string $secret The secret.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-2.1
	 */
	public function hashOf(string $secret): string {
		return hash('sha256', $secret);
	}//end hashOf()

	/**
	 * The active channel with this token.
	 *
	 * @param string $token The channel token.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws WebChatRefusal When there is none.
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-2.1
	 */
	public function channelOrRefuse(string $token): array {
		try {
			$channel = $this->store->activeChannel(token: $token);
		} catch (RuntimeException $e) {
			$this->logger->error('Pipelinq web chat: channels cannot be read', ['exception' => $e->getMessage()]);
			throw new WebChatRefusal(message: 'The chat is not available right now.', status: 503);
		}

		if ($channel === null) {
			throw new WebChatRefusal(message: 'This chat is not available.', status: 404);
		}

		return $channel;
	}//end channelOrRefuse()

	/**
	 * The channel and conversation of a chat whose secret is right.
	 *
	 * @param string $token          The channel token.
	 * @param string $conversationId The chat.
	 * @param string $secret         The visitor's chat secret.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
	 *
	 * @throws WebChatRefusal When the chat is not on this channel or the secret is wrong.
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-2.1
	 */
	public function chatOrRefuse(string $token, string $conversationId, string $secret): array {
		$channel      = $this->channelOrRefuse(token: $token);
		$conversation = null;
		if ($conversationId !== '') {
			$conversation = $this->store->conversation(id: $conversationId);
		}

		if ($conversation === null
			|| ($conversation['channel'] ?? null) !== 'chat'
			|| (string)($conversation['webChatChannel'] ?? '') !== $this->store->idOf(row: $channel)
		) {
			throw new WebChatRefusal(message: 'This chat is not known.', status: 404);
		}

		$hash = (string)($conversation['visitorSecretHash'] ?? '');
		if ($secret === '' || $hash === '' || hash_equals($hash, $this->hashOf(secret: $secret)) === false) {
			throw new WebChatRefusal(message: 'This chat is not known.', status: 403);
		}

		return [$channel, $conversation];
	}//end chatOrRefuse()

	/**
	 * A chat conversation the current colleague may read.
	 *
	 * @param string $conversationId The chat.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws WebChatRefusal When they may not, or it is not a chat.
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-3.2
	 */
	public function readableOrRefuse(string $conversationId): array {
		$conversation = $this->store->readableConversation(id: $conversationId);
		if ($conversation === null || ($conversation['channel'] ?? null) !== 'chat') {
			throw new WebChatRefusal(message: 'This chat is not known.', status: 404);
		}

		return $conversation;
	}//end readableOrRefuse()

	/**
	 * A chat conversation the current colleague may read and that has not ended.
	 *
	 * @param string $conversationId The chat.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws WebChatRefusal When they may not, or it has ended.
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-3.2
	 */
	public function openReadableOrRefuse(string $conversationId): array {
		$conversation = $this->readableOrRefuse(conversationId: $conversationId);
		if (($conversation['status'] ?? 'open') === 'closed') {
			throw new WebChatRefusal(message: 'This chat has ended.', status: 409);
		}

		return $conversation;
	}//end openReadableOrRefuse()
}//end class
