<?php

/**
 * The assistant's turn in a website chat: its answer stored, and the answers without a source counted.
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
 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-4.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\WebChat;

/**
 * Asks the assistant, stores its answer with its sources, and counts the
 * answers in a row without a source, after which the chat goes to a person (D5).
 *
 * @spec openspec/specs/web-chat-and-handover/spec.md#requirement-the-assistant-answers-first-when-switched-on-req-wch-003
 */
class WebChatAssistantTurn {

	/**
	 * Constructor.
	 *
	 * @param ChatAssistant   $assistant The assistant that may answer first.
	 * @param WebChatStore    $store     Where the answer is kept.
	 * @param WebChatMessages $messages  The message rows.
	 */
	public function __construct(
		private readonly ChatAssistant $assistant,
		private readonly WebChatStore $store,
		private readonly WebChatMessages $messages,
	) {
	}//end __construct()

	/**
	 * Whether the assistant answers first on this channel.
	 *
	 * @param array<string, mixed> $channel The channel.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-4.1
	 */
	public function answersFor(array $channel): bool {
		return $this->assistant->answersFor(channel: $channel);
	}//end answersFor()

	/**
	 * Let the assistant answer; counts the answers in a row without a source.
	 *
	 * @param array<string, mixed>                 $channel        The channel.
	 * @param array<string, mixed>                 $conversation   The conversation.
	 * @param string                               $conversationId Its id.
	 * @param array{text: string, locale: string} $question       The visitor's message and language.
	 *
	 * @return array<string, mixed> The conversation, with `assistantFailed` when there was no answer.
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-4.1
	 */
	public function answer(array $channel, array $conversation, string $conversationId, array $question): array {
		$reply = $this->assistant->ask(
			channelId: $this->store->idOf(row: $channel),
			question: $question['text'],
			locale: $question['locale'],
			token: (string)($conversation['assistantToken'] ?? '')
		);
		if ($reply === null) {
			$conversation['assistantFailed'] = true;
			return $conversation;
		}

		$this->store->addMessage(
			message: $this->messages->row(
				conversationId: $conversationId,
				direction: 'outbound',
				body: $reply['answer'],
				metadata: ['author' => 'assistant', 'sources' => $reply['sources'], 'disclosure' => $reply['disclosure']]
			)
		);
		$previous = (int)($conversation['unsourcedAnswers'] ?? 0);
		$conversation['assistantToken']   = $reply['token'];
		$conversation['unsourcedAnswers'] = 0;
		if ($reply['sources'] === []) {
			$conversation['unsourcedAnswers'] = ($previous + 1);
		}

		return $conversation;
	}//end answer()
}//end class
