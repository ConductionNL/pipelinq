<?php

/**
 * Pipelinq ReadsMetaConversations.
 *
 * The inbox half of a Meta page adapter (requests-inbound-to-queue D5): the
 * one brokered read of a page's or business account's recent conversations,
 * and the visitor messages in what comes back. A message the page itself sent
 * is not a visitor message and is left out, so an answer an agent gives never
 * opens a ticket of its own.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Social
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-facebook-and-instagram-messages-reach-the-queue-req-itq-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Social;

/**
 * Reads a Meta account's conversations through the broker.
 *
 * The using class supplies {@see messagingPlatform()} and the Graph version.
 *
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-facebook-and-instagram-messages-reach-the-queue-req-itq-004
 */
trait ReadsMetaConversations {

	/**
	 * The `platform` Meta's conversations edge is asked for.
	 *
	 * @return string `messenger` or `instagram`.
	 *
	 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-facebook-and-instagram-messages-reach-the-queue-req-itq-004
	 */
	abstract public function messagingPlatform(): string;

	/**
	 * The read of the account's newest conversations with their newest messages.
	 *
	 * @param array<string, mixed> $account The socialAccount record.
	 *
	 * @return SocialAdapterCall|null The request, or null when the account names no page.
	 *
	 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-facebook-and-instagram-messages-reach-the-queue-req-itq-004
	 */
	public function conversationsCall(array $account): ?SocialAdapterCall {
		$pageId = trim((string) ($account['externalAccountId'] ?? ''));
		if ($pageId === '') {
			return null;
		}

		// One page of 25 conversations with their 10 newest messages each: a
		// busy account's backlog drains over several runs, not in one.
		$query = http_build_query(
			[
				'platform' => $this->messagingPlatform(),
				'limit' => 25,
				'fields' => 'id,updated_time,messages.limit(10){id,message,created_time,from}',
			],
			'',
			'&',
			PHP_QUERY_RFC3986
		);

		return new SocialAdapterCall(
			method: 'GET',
			path: '/' . self::GRAPH_VERSION . '/' . rawurlencode($pageId) . '/conversations?' . $query
		);
	}//end conversationsCall()

	/**
	 * The visitor messages in a conversations answer, oldest first.
	 *
	 * @param array<string, mixed> $payload The decoded answer.
	 * @param array<string, mixed> $account The socialAccount record; its page id marks the page's own messages.
	 *
	 * @return list<array{conversationId: string, messageId: string, text: string, fromId: string, fromName: string, createdAt: int}>
	 *         The messages; a message without text, time or sender, or sent by the page, is left out.
	 *
	 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-facebook-and-instagram-messages-reach-the-queue-req-itq-004
	 */
	public function visitorMessages(array $payload, array $account): array {
		$pageId = trim((string) ($account['externalAccountId'] ?? ''));
		$out    = [];
		foreach ($this->listOf(value: ($payload['data'] ?? [])) as $conversation) {
			$conversationId = trim((string) ($conversation['id'] ?? ''));
			if ($conversationId === '') {
				continue;
			}

			foreach ($this->listOf(value: ($conversation['messages']['data'] ?? [])) as $message) {
				$read = $this->visitorMessage(message: $message, conversationId: $conversationId, pageId: $pageId);
				if ($read !== null) {
					$out[] = $read;
				}
			}
		}

		usort($out, static fn (array $left, array $right): int => ($left['createdAt'] <=> $right['createdAt']));

		return $out;
	}//end visitorMessages()

	/**
	 * One message as a visitor message, or null when it is not one.
	 *
	 * @param array<string, mixed> $message        The Graph message.
	 * @param string               $conversationId The conversation it is on.
	 * @param string               $pageId         The page's own id.
	 *
	 * @return array{conversationId: string, messageId: string, text: string, fromId: string, fromName: string, createdAt: int}|null
	 */
	private function visitorMessage(array $message, string $conversationId, string $pageId): ?array {
		$from   = $message['from'] ?? [];
		$fromId = '';
		$name   = '';
		if (is_array($from) === true) {
			$fromId = trim((string) ($from['id'] ?? ''));
			$name   = trim((string) ($from['name'] ?? ($from['username'] ?? '')));
		}

		$text = trim((string) ($message['message'] ?? ''));
		$at   = strtotime((string) ($message['created_time'] ?? ''));
		if ($fromId === '' || $fromId === $pageId || $text === '' || $at === false) {
			return null;
		}

		return [
			'conversationId' => $conversationId,
			'messageId' => trim((string) ($message['id'] ?? '')),
			'text' => $text,
			'fromId' => $fromId,
			'fromName' => $name,
			'createdAt' => $at,
		];
	}//end visitorMessage()

	/**
	 * The arrays in a value that should be a list; none when it is not one.
	 *
	 * @param mixed $value The value.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function listOf(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return array_values(array_filter($value, 'is_array'));
	}//end listOf()
}//end trait
