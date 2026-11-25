<?php

/**
 * What a website chat's windows show: where the chat stands and its messages.
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

use OCA\Pipelinq\Service\RoutingService;

/**
 * The visitor's and the colleague's view of a chat.
 *
 * @spec openspec/specs/web-chat-and-handover/spec.md#requirement-a-visitor-chats-without-an-account-req-wch-002
 */
class WebChatWindow {

	/**
	 * The default wait for a colleague, in seconds (D2).
	 *
	 * @var int
	 */
	public const DEFAULT_WAIT = 180;

	/**
	 * Constructor.
	 *
	 * @param WebChatStore    $store     Where the chat is kept.
	 * @param WebChatMessages $messages  How a message is shown, and the times.
	 * @param ChatAssistant   $assistant Whether the assistant answers on a channel.
	 * @param RoutingService  $routing   Which agent profiles are available.
	 */
	public function __construct(
		private readonly WebChatStore $store,
		private readonly WebChatMessages $messages,
		private readonly ChatAssistant $assistant,
		private readonly RoutingService $routing,
	) {
	}//end __construct()

	/**
	 * The visitor window's view of the chat.
	 *
	 * @param array<string, mixed>      $channel        The channel.
	 * @param string                    $conversationId The chat.
	 * @param string                    $since          The time of the last message the window has, '' for all.
	 * @param array<string, mixed>|null $conversation   The conversation when already read.
	 *
	 * @return array{state: string, messages: list<array<string, mixed>>, offlineText: string}
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-2.1
	 */
	public function visitorView(array $channel, string $conversationId, string $since, ?array $conversation=null): array {
		$conversation = ($conversation ?? ($this->store->conversation(id: $conversationId) ?? []));
		$showName     = ($channel['showAgentFirstName'] ?? false) === true;
		$messages     = [];
		foreach ($this->store->messages(conversationId: $conversationId) as $message) {
			if ($since === '' || strcmp((string)($message['sentAt'] ?? ''), $since) > 0) {
				$messages[] = $this->messages->view(message: $message, showAgentName: $showName);
			}
		}

		return [
			'state'       => $this->state(channel: $channel, conversation: $conversation),
			'messages'    => $messages,
			'offlineText' => (string)($channel['offlineText'] ?? ''),
		];
	}//end visitorView()

	/**
	 * A chat as the colleague on its ticket sees it.
	 *
	 * @param string               $conversationId The chat.
	 * @param array<string, mixed> $conversation   The conversation, read through the colleague's checks.
	 *
	 * @return array{conversation: string, status: string, assignedTo: string, ticket: string, messages: list<array<string, mixed>>}
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-3.2
	 */
	public function agentView(string $conversationId, array $conversation): array {
		return [
			'conversation' => $conversationId,
			'status'       => (string)($conversation['status'] ?? 'open'),
			'assignedTo'   => (string)($conversation['assignedTo'] ?? ''),
			'ticket'       => (string)($conversation['ticket'] ?? ''),
			'messages'     => array_map(
				fn (array $message): array => $this->messages->threadLine(message: $message),
				$this->store->messages(conversationId: $conversationId)
			),
		];
	}//end agentView()

	/**
	 * How the window opens: with the assistant, with a person, or asking for an address.
	 *
	 * @param array<string, mixed> $channel The channel.
	 *
	 * @return string One of assistant, person, offline.
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-2.1
	 */
	public function startMode(array $channel): string {
		if ($this->assistant->answersFor(channel: $channel) === true) {
			return 'assistant';
		}

		if ($this->agentsAvailable() === true) {
			return 'person';
		}

		return 'offline';
	}//end startMode()

	/**
	 * Where the chat stands: closed, with a colleague, waiting for one, waited out, or with the assistant.
	 *
	 * @param array<string, mixed> $channel      The channel.
	 * @param array<string, mixed> $conversation The conversation.
	 *
	 * @return string One of closed, with-agent, waiting, offline, assistant, person.
	 */
	private function state(array $channel, array $conversation): string {
		$status = (string)($conversation['status'] ?? 'open');
		if ($status === 'closed') {
			return 'closed';
		}

		if ($status === 'assigned') {
			return 'with-agent';
		}

		if ((string)($conversation['ticket'] ?? '') !== '') {
			return $this->handedOverState(channel: $channel, conversation: $conversation);
		}

		return $this->startMode(channel: $channel);
	}//end state()

	/**
	 * A handed-over chat waits for a colleague until the channel's wait is over.
	 *
	 * @param array<string, mixed> $channel      The channel.
	 * @param array<string, mixed> $conversation The conversation.
	 *
	 * @return string waiting or offline.
	 */
	private function handedOverState(array $channel, array $conversation): string {
		$wait    = (int)($channel['waitSeconds'] ?? self::DEFAULT_WAIT);
		$elapsed = $this->messages->secondsSince(value: ($conversation['handedOverAt'] ?? ''));
		if ($elapsed >= 0 && $elapsed >= $wait) {
			return 'offline';
		}

		return 'waiting';
	}//end handedOverState()

	/**
	 * Whether a colleague can pick up: no agent profiles kept, or one that is available (D7).
	 *
	 * @return bool
	 */
	private function agentsAvailable(): bool {
		$profiles = $this->store->agentProfiles();
		return $profiles === [] || $this->routing->filterByAvailability(profiles: $profiles) !== [];
	}//end agentsAvailable()
}//end class
