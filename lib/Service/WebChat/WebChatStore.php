<?php

/**
 * Where a website chat is kept: its channel, its conversation and its messages in OpenRegister.
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
 * @spec openspec/changes/requests-web-chat-and-bot/specs/web-chat-and-handover/spec.md#requirement-a-visitor-chats-without-an-account-req-wch-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\WebChat;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Util\ConfiguredSchemaSlug;
use OCA\Pipelinq\Util\PayloadToArray;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

/**
 * Reads and writes the rows of a website chat.
 *
 * The public chat routes run for an anonymous visitor, so the lookups that
 * find a chat's channel and conversation read past RBAC, as the messaging
 * webhooks' lookups do: the row stays in-process and only what the visitor's
 * window needs leaves the service. Writes go through OpenRegister's checks,
 * as whichever account the caller runs them as (the messaging service account
 * for a visitor, the colleague for an answer).
 *
 * @spec openspec/specs/web-chat-and-handover/spec.md#requirement-a-visitor-chats-without-an-account-req-wch-002
 */
class WebChatStore {
	use ConfiguredSchemaSlug;
	use PayloadToArray;

	/**
	 * The most messages one chat is read with.
	 *
	 * @var int
	 */
	public const MESSAGE_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The container OpenRegister's object service is resolved from.
	 * @param IAppConfig         $appConfig Where the register and schema slugs can be overridden.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The active channel with this token, or null.
	 *
	 * @param string $token The channel token from the embed line.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-2.1
	 */
	public function activeChannel(string $token): ?array {
		if (preg_match('/^[A-Za-z0-9]{16,64}$/', $token) !== 1) {
			return null;
		}

		foreach ($this->rows(schema: $this->channelSchema(), filters: ['channelToken' => $token], sort: [], limit: 5) as $channel) {
			if (($channel['channelToken'] ?? null) === $token && ($channel['active'] ?? false) === true) {
				return $channel;
			}
		}

		return null;
	}//end activeChannel()

	/**
	 * A chat conversation by id, read past RBAC; null when there is none.
	 *
	 * @param string $id The conversation id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-2.1
	 */
	public function conversation(string $id): ?array {
		return $this->one(id: $id, schema: $this->conversationSchema(), rbac: false);
	}//end conversation()

	/**
	 * A chat conversation by id as the current user may read it; null when they may not.
	 *
	 * @param string $id The conversation id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-3.2
	 */
	public function readableConversation(string $id): ?array {
		return $this->one(id: $id, schema: $this->conversationSchema(), rbac: true);
	}//end readableConversation()

	/**
	 * A web chat channel by id, read past RBAC; null when there is none.
	 *
	 * @param string $id The channel id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-3.2
	 */
	public function channel(string $id): ?array {
		return $this->one(id: $id, schema: $this->channelSchema(), rbac: false);
	}//end channel()

	/**
	 * The messages of a chat, oldest first.
	 *
	 * @param string $conversationId The conversation id.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-2.1
	 */
	public function messages(string $conversationId): array {
		$messages = array_values(
			array_filter(
				$this->rows(
					schema: $this->messageSchema(),
					filters: ['conversationId' => $conversationId, 'channel' => 'chat'],
					sort: ['sentAt' => 'asc'],
					limit: self::MESSAGE_LIMIT
				),
				static fn (array $row): bool => ($row['conversationId'] ?? null) === $conversationId
			)
		);
		usort($messages, static fn (array $left, array $right): int => strcmp((string)($left['sentAt'] ?? ''), (string)($right['sentAt'] ?? '')));
		return $messages;
	}//end messages()

	/**
	 * The agent profiles, read past RBAC; an empty list when none is kept.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-3.3
	 */
	public function agentProfiles(): array {
		try {
			return $this->rows(schema: $this->resolveSchemaSlug(key: 'agentProfile_schema', default: 'agentProfile'), filters: [], sort: [], limit: 500);
		} catch (RuntimeException) {
			return [];
		}
	}//end agentProfiles()

	/**
	 * Write a conversation; answers the stored row.
	 *
	 * @param array<string, mixed> $conversation The conversation.
	 * @param string|null          $id           Its id, null for a new one.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When OpenRegister is absent or refuses the write.
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-2.1
	 */
	public function saveConversation(array $conversation, ?string $id=null): array {
		unset($conversation['@self'], $conversation['id'], $conversation['uuid']);
		return $this->save(object: $conversation, schema: $this->conversationSchema(), id: $id);
	}//end saveConversation()

	/**
	 * Write a new chat message; answers the stored row.
	 *
	 * @param array<string, mixed> $message The message.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When OpenRegister is absent or refuses the write.
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-2.1
	 */
	public function addMessage(array $message): array {
		return $this->save(object: $message, schema: $this->messageSchema(), id: null);
	}//end addMessage()

	/**
	 * The id of a row.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-2.1
	 */
	public function idOf(array $row): string {
		$candidates = [($row['id'] ?? null), ($row['uuid'] ?? null)];
		$self       = ($row['@self'] ?? []);
		if (is_array($self) === true) {
			$candidates[] = ($self['id'] ?? null);
		}

		foreach ($candidates as $candidate) {
			if (is_string($candidate) === true && $candidate !== '') {
				return $candidate;
			}
		}

		return '';
	}//end idOf()

	/**
	 * One row by id.
	 *
	 * @param string $id     The id.
	 * @param string $schema The schema slug.
	 * @param bool   $rbac   Whether OpenRegister's checks apply.
	 *
	 * @return array<string, mixed>|null
	 */
	private function one(string $id, string $schema, bool $rbac): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$found = $this->objects()->find(
				id: $id,
				options: new ObjectFindOptions(register: $this->register(), schema: $schema),
				_rbac: $rbac,
				_multitenancy: $rbac
			);
		} catch (RuntimeException $e) {
			throw $e;
		} catch (Throwable) {
			return null;
		}

		if ($found === null) {
			return null;
		}

		$row = $this->toArray(value: $found);
		if ($this->idOf(row: $row) === '') {
			$row['id'] = $id;
		}

		return $row;
	}//end one()

	/**
	 * The rows of a schema that match the filters, read past RBAC.
	 *
	 * @param string                $schema  The schema slug.
	 * @param array<string, mixed>  $filters The filters.
	 * @param array<string, string> $sort    The sort.
	 * @param int                   $limit   The most rows.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws RuntimeException When OpenRegister is absent or the read fails.
	 */
	private function rows(string $schema, array $filters, array $sort, int $limit): array {
		try {
			$found = $this->objects()->findAll(
				config: ['filters' => array_merge(['register' => $this->register(), 'schema' => $schema], $filters), 'sort' => $sort, 'limit' => $limit],
				_rbac: false,
				_multitenancy: false
			);
		} catch (RuntimeException $e) {
			throw $e;
		} catch (Throwable $e) {
			throw new RuntimeException('The web chat rows could not be read: ' . $e->getMessage(), 0, $e);
		}

		$rows = [];
		foreach ($found as $object) {
			$rows[] = $this->toArray(value: $object);
		}

		return $rows;
	}//end rows()

	/**
	 * Write one row.
	 *
	 * @param array<string, mixed> $object The row.
	 * @param string               $schema The schema slug.
	 * @param string|null          $id     Its id, null for a new one.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When OpenRegister is absent or refuses the write.
	 */
	private function save(array $object, string $schema, ?string $id): array {
		try {
			$saved = $this->objects()->saveObject(
				object: $object,
				options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $this->register(), schema: $schema), uuid: $id)
			);
		} catch (RuntimeException $e) {
			throw $e;
		} catch (Throwable $e) {
			throw new RuntimeException('The web chat row could not be written: ' . $e->getMessage(), 0, $e);
		}

		$row = $this->toArray(value: $saved);
		if ($this->idOf(row: $row) === '' && $id !== null) {
			$row['id'] = $id;
		}

		return $row;
	}//end save()

	/**
	 * OpenRegister's object service.
	 *
	 * @return object
	 *
	 * @throws RuntimeException When OpenRegister is not installed.
	 */
	private function objects(): object {
		if (class_exists('OCA\OpenRegister\Service\ObjectService') === false) {
			throw new RuntimeException('OpenRegister is not available.');
		}

		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			throw new RuntimeException('OpenRegister is not available.', 0, $e);
		}
	}//end objects()

	/**
	 * The pipelinq register slug; never empty.
	 *
	 * @return string
	 */
	private function register(): string {
		return $this->resolveSchemaSlug(key: 'register', default: 'pipelinq');
	}//end register()

	/**
	 * The web chat channel schema slug.
	 *
	 * @return string
	 */
	private function channelSchema(): string {
		return $this->resolveSchemaSlug(key: 'webChatChannel_schema', default: 'webChatChannel');
	}//end channelSchema()

	/**
	 * The conversation schema slug.
	 *
	 * @return string
	 */
	private function conversationSchema(): string {
		return $this->resolveSchemaSlug(key: 'conversation_schema', default: 'channelConversation');
	}//end conversationSchema()

	/**
	 * The message schema slug.
	 *
	 * @return string
	 */
	private function messageSchema(): string {
		return $this->resolveSchemaSlug(key: 'message_schema', default: 'channelMessage');
	}//end messageSchema()
}//end class
