<?php

/**
 * The messages of a website chat: new rows, how a window shows them, and the transcript.
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

use DateTimeInterface;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Builds and reads chat message rows, and the times they carry.
 *
 * @spec openspec/specs/web-chat-and-handover/spec.md#requirement-a-visitor-chats-without-an-account-req-wch-002
 */
class WebChatMessages {

	/**
	 * Seconds without a message after which a chat ends (D8).
	 *
	 * @var int
	 */
	public const IDLE_SECONDS = 1800;

	/**
	 * Who said a message, by its author.
	 *
	 * @var array<string, string>
	 */
	public const AUTHOR_NAMES = ['visitor' => 'Visitor', 'assistant' => 'Assistant', 'agent' => 'Colleague'];

	/**
	 * Constructor.
	 *
	 * @param WebChatStore $store Where the chat is kept (the id of a row).
	 * @param ITimeFactory $time  The time a message is recorded at.
	 */
	public function __construct(
		private readonly WebChatStore $store,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * A new chat message row.
	 *
	 * @param string               $conversationId The chat.
	 * @param string               $direction      inbound or outbound.
	 * @param string               $body           The text.
	 * @param array<string, mixed> $metadata       Who said it, and the assistant's sources.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-2.1
	 */
	public function row(string $conversationId, string $direction, string $body, array $metadata): array {
		return [
			'conversationId' => $conversationId,
			'channel'        => 'chat',
			'direction'      => $direction,
			'body'           => $body,
			'deliveryStatus' => 'delivered',
			'metadata'       => $metadata,
			'sentAt'         => $this->now(),
		];
	}//end row()

	/**
	 * A message as a window shows it.
	 *
	 * @param array<string, mixed> $message       The channelMessage row.
	 * @param bool                 $showAgentName Whether a colleague's first name is shown.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-2.1
	 */
	public function view(array $message, bool $showAgentName): array {
		$metadata = ($message['metadata'] ?? []);
		if (is_array($metadata) === false) {
			$metadata = [];
		}

		$author = $this->authorOf(message: $message, metadata: $metadata);
		$view   = [
			'id'     => $this->store->idOf(row: $message),
			'author' => $author,
			'body'   => (string)($message['body'] ?? ''),
			'at'     => (string)($message['sentAt'] ?? ''),
		];
		if ($author === 'assistant') {
			$view['sources']    = (array)($metadata['sources'] ?? []);
			$view['disclosure'] = (string)($metadata['disclosure'] ?? '');
		}

		if ($author === 'agent' && $showAgentName === true && (string)($metadata['agentFirstName'] ?? '') !== '') {
			$view['agentName'] = (string)$metadata['agentFirstName'];
		}

		return $view;
	}//end view()

	/**
	 * The first thing the visitor asked, as the ticket title.
	 *
	 * @param list<array<string, mixed>> $messages The chat's messages.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-3.1
	 */
	public function firstQuestion(array $messages): string {
		foreach ($messages as $message) {
			if (($message['direction'] ?? '') === 'inbound' && trim((string)($message['body'] ?? '')) !== '') {
				return trim((string)$message['body']);
			}
		}

		return 'Website chat';
	}//end firstQuestion()

	/**
	 * The chat's stored messages, oldest first.
	 *
	 * @param string $conversationId The chat.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-3.1
	 */
	public function of(string $conversationId): array {
		return $this->store->messages(conversationId: $conversationId);
	}//end of()

	/**
	 * A message as the colleague's thread draws it: the view, its side (`us` for the
	 * assistant and colleagues, `them` for the visitor) and who said it.
	 *
	 * @param array<string, mixed> $message The stored message.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-3.2
	 */
	public function threadLine(array $message): array {
		$view = $this->view(message: $message, showAgentName: true);
		$name = self::AUTHOR_NAMES[$view['author']] ?? self::AUTHOR_NAMES['visitor'];
		if ($view['author'] === 'agent' && ($view['agentName'] ?? '') !== '') {
			$name = (string)$view['agentName'];
		}

		$view['side'] = 'us';
		if ($view['author'] === 'visitor') {
			$view['side'] = 'them';
		}

		$view['authorName'] = $name;
		return $view;
	}//end threadLine()

	/**
	 * The chat so far, one line per message, saying who said it.
	 *
	 * @param list<array<string, mixed>> $messages The chat's messages.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-3.1
	 */
	public function transcript(array $messages): string {
		$names = self::AUTHOR_NAMES;
		$lines = [];
		foreach ($messages as $message) {
			$author  = $this->view(message: $message, showAgentName: false)['author'];
			$lines[] = ($names[$author] ?? 'Visitor') . ': ' . trim((string)($message['body'] ?? ''));
		}

		return implode("\n", $lines);
	}//end transcript()

	/**
	 * Whether a chat had no message for the idle time (D8).
	 *
	 * @param array<string, mixed> $conversation The conversation.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-2.1
	 */
	public function idle(array $conversation): bool {
		return $this->secondsSince(value: ($conversation['lastMessageAt'] ?? '')) >= self::IDLE_SECONDS;
	}//end idle()

	/**
	 * Seconds since an ISO 8601 time, or -1 when it cannot be read.
	 *
	 * @param mixed $value An ISO 8601 time.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-2.1
	 */
	public function secondsSince(mixed $value): int {
		if (is_string($value) === false || $value === '') {
			return -1;
		}

		$parsed = date_create_immutable_from_format(DateTimeInterface::ATOM, $value);
		if ($parsed === false) {
			return -1;
		}

		return ($this->time->getTime() - $parsed->getTimestamp());
	}//end secondsSince()

	/**
	 * Now, as ISO 8601.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-2.1
	 */
	public function now(): string {
		return $this->time->getDateTime()->format(DateTimeInterface::ATOM);
	}//end now()

	/**
	 * Who said a message: the metadata's author, else visitor for inbound and agent for outbound.
	 *
	 * @param array<string, mixed> $message  The channelMessage row.
	 * @param array<string, mixed> $metadata Its metadata.
	 *
	 * @return string
	 */
	private function authorOf(array $message, array $metadata): string {
		$author = 'agent';
		if (($message['direction'] ?? '') === 'inbound') {
			$author = 'visitor';
		}

		return (string)($metadata['author'] ?? $author);
	}//end authorOf()
}//end class
