<?php

/**
 * The assistant that answers a website chat first: hermiq's public chat, when it is there.
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
 * @spec openspec/changes/requests-web-chat-and-bot/specs/web-chat-and-handover/spec.md#requirement-the-assistant-answers-first-when-switched-on-req-wch-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\WebChat;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves hermiq's public chat entry point lazily, the way RelevanceScorer
 * resolves its provider (design D4): pipelinq calls no model itself. With
 * hermiq absent, the entry point missing, or the channel's assistant off,
 * there is no assistant and the chat goes to a person.
 */
class ChatAssistant {

	/**
	 * hermiq's entry point for sibling apps (hermiq change delivery-public-web-chat, D2).
	 *
	 * @var string
	 */
	public const ENTRY_POINT = 'OCA\Hermiq\PublicChat\PublicChatEntryPoint';

	/**
	 * The scope of content the assistant may answer from: what OpenRegister serves anonymously.
	 *
	 * @var string
	 */
	public const SOURCE_SCOPE = 'public';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The container hermiq's entry point is resolved from.
	 * @param LoggerInterface    $logger    Where a failed answer is logged.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether this channel's chats are answered by the assistant first.
	 *
	 * @param array<string, mixed> $channel The web chat channel.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-4.1
	 */
	public function answersFor(array $channel): bool {
		return ($channel['assistantEnabled'] ?? false) === true && $this->entryPoint() !== null;
	}//end answersFor()

	/**
	 * The channel key hermiq registers this channel under.
	 *
	 * @param string $channelId The web chat channel's id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-4.1
	 */
	public function channelKey(string $channelId): string {
		return 'app:pipelinq:' . $channelId;
	}//end channelKey()

	/**
	 * Ask the assistant; null when there is no assistant or it could not answer.
	 *
	 * @param string $channelId The web chat channel's id.
	 * @param string $question  The visitor's message.
	 * @param string $locale    The visitor's language.
	 * @param string $token     The assistant's conversation token of this chat, '' for a first question.
	 *
	 * @return array{answer: string, sources: list<array{title: string, url: string}>, token: string, disclosure: string}|null
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-4.1
	 */
	public function ask(string $channelId, string $question, string $locale, string $token): ?array {
		$entryPoint = $this->entryPoint();
		if ($entryPoint === null) {
			return null;
		}

		try {
			$reply = $entryPoint->ask(
				channelKey: $this->channelKey(channelId: $channelId),
				question: $question,
				locale: $locale,
				sourceScope: self::SOURCE_SCOPE,
				conversationToken: $token,
			);
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq web chat: the assistant did not answer; the chat goes to a person', ['exception' => $e->getMessage()]);
			return null;
		}

		return $this->normalise(reply: $reply, token: $token);
	}//end ask()

	/**
	 * hermiq's reply as this chat keeps it; null when it holds no answer.
	 *
	 * @param mixed  $reply The entry point's reply, an array or an object with public fields.
	 * @param string $token The token the question was asked under.
	 *
	 * @return array{answer: string, sources: list<array{title: string, url: string}>, token: string, disclosure: string}|null
	 */
	private function normalise(mixed $reply, string $token): ?array {
		if (is_object($reply) === true) {
			$reply = get_object_vars($reply);
		}

		if (is_array($reply) === false) {
			return null;
		}

		$answer = trim((string)($reply['answer'] ?? ''));
		if ($answer === '') {
			return null;
		}

		$newToken = trim((string)($reply['conversationToken'] ?? ''));
		if ($newToken === '') {
			$newToken = $token;
		}

		return [
			'answer'     => $answer,
			'sources'    => $this->sources(raw: ($reply['sources'] ?? [])),
			'token'      => $newToken,
			'disclosure' => trim((string)($reply['disclosure'] ?? '')),
		];
	}//end normalise()

	/**
	 * The sources of an answer, each with a title and a link; entries without a link are left out.
	 *
	 * @param mixed $raw The sources hermiq gave.
	 *
	 * @return list<array{title: string, url: string}>
	 */
	private function sources(mixed $raw): array {
		if (is_array($raw) === false) {
			return [];
		}

		$sources = [];
		foreach ($raw as $source) {
			if (is_object($source) === true) {
				$source = get_object_vars($source);
			}

			if (is_array($source) === false) {
				continue;
			}

			$url = trim((string)($source['url'] ?? ''));
			if (preg_match('#^https?://#i', $url) !== 1) {
				continue;
			}

			$title = trim((string)($source['title'] ?? ''));
			if ($title === '') {
				$title = $url;
			}

			$sources[] = ['title' => $title, 'url' => $url];
		}

		return $sources;
	}//end sources()

	/**
	 * hermiq's entry point, or null when hermiq is absent or does not offer it.
	 *
	 * @return object|null
	 */
	private function entryPoint(): ?object {
		try {
			$entryPoint = $this->container->get(self::ENTRY_POINT);
		} catch (Throwable) {
			return null;
		}

		if (is_object($entryPoint) === false || method_exists($entryPoint, 'ask') === false) {
			return null;
		}

		return $entryPoint;
	}//end entryPoint()
}//end class
