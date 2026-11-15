<?php

/**
 * Pipelinq ChannelAdapterLookups.
 *
 * Lookups shared by the SMS and WhatsApp channel adapters.
 *
 * @category Util
 * @package  OCA\Pipelinq\Service\Support
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Support;

use OCA\Pipelinq\Service\ContactmomentService;
use OCA\Pipelinq\Util\ConfiguredSchemaSlug;
use OCA\Pipelinq\Util\ConfiguredTenantId;
use Throwable;

/**
 * Contact-moment service, phone, tenant and schema-slug reads, the message
 * save and the conversation lookup of a channel adapter. The host class provides IAppConfig `$appConfig`,
 * ContainerInterface `$container` and LoggerInterface `$logger` properties,
 * getObjectService(), getRegisterSlug(), toArray(), extractId() and nowIso()
 * steps and DEFAULT_MESSAGE_SCHEMA_SLUG, DEFAULT_CONVERSATION_SCHEMA_SLUG,
 * CONVERSATION_LOOKUP_FAILURE and CONVERSATION_OPENING_STAMPS constants.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait ChannelAdapterLookups {
	use ConfiguredTenantId;
	use ConfiguredSchemaSlug;

	/**
	 * The contact moment service, resolved lazily; null when it is absent.
	 *
	 * @return ContactmomentService|null The service or null.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function contactmoments(): ?ContactmomentService {
		try {
			$service = $this->container->get('OCA\\Pipelinq\\Service\\ContactmomentService');
		} catch (Throwable $e) {
			return null;
		}

		if (($service instanceof ContactmomentService) === false) {
			return null;
		}

		return $service;
	}//end contactmoments()

	/**
	 * Extract a phone number from a contact row.
	 *
	 * @param array<string, mixed> $contact Contact row.
	 *
	 * @return string Phone number or empty.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function extractPhone(array $contact): string {
		foreach (['phoneNumber', 'mobile', 'telephone', 'phone'] as $key) {
			$value = ($contact[$key] ?? null);
			if (is_string($value) === true && $value !== '') {
				return $value;
			}
		}

		return '';
	}//end extractPhone()

	/**
	 * Persist a message payload via OpenRegister.
	 *
	 * @param array<string, mixed> $payload Payload.
	 *
	 * @return array<string, mixed>|null Saved row.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function persistMessage(array $payload): ?array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$saved = $objectService->saveObject(
				object: $payload,
				register: $this->getRegisterSlug(),
				schema: $this->resolveSchemaSlug(key: 'message_schema', default: self::DEFAULT_MESSAGE_SCHEMA_SLUG),
				uuid: null,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				substr(strrchr(self::class, '\\'), 1).'.persistMessage: save failed',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		return $this->toArray(value: $saved);
	}//end persistMessage()
	/**
	 * Look up the open conversation for (contact, provider, channel)
	 * or open a new one.
	 *
	 * @param string $contactId Contact UUID.
	 * @param string $providerId Provider UUID.
	 * @param string $channel Channel.
	 * @param array<string, bool> $scope OpenRegister access scope for the lookup (LOOKUP_SCOPE from the webhook); the open runs as the caller.
	 *
	 * @return string Conversation UUID.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function findOrOpenConversation(string $contactId, string $providerId, string $channel, array $scope = []): string {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return '';
		}

		$schema = $this->resolveSchemaSlug(key: 'conversation_schema', default: self::DEFAULT_CONVERSATION_SCHEMA_SLUG);
		$conversation = ['contactId' => $contactId, 'providerId' => $providerId, 'channel' => $channel, 'status' => 'open'];
		$existingId = $this->findOpenConversation(objectService: $objectService, conversation: $conversation, schema: $schema, scope: $scope);
		if ($existingId !== null) {
			return $existingId;
		}

		return $this->openConversation(objectService: $objectService, conversation: $conversation, schema: $schema);
	}//end findOrOpenConversation()

	/**
	 * The id of the first open conversation matching the identity, or null when there is none.
	 * A failed lookup is logged when the host's CONVERSATION_LOOKUP_FAILURE message is not empty.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param array<string, string> $conversation The contact, provider, channel and open status.
	 * @param string $schema The conversation schema slug.
	 * @param array<string, bool> $scope OpenRegister access scope for the lookup.
	 *
	 * @return string|null The conversation id, or null when none is open.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function findOpenConversation(object $objectService, array $conversation, string $schema, array $scope): string|null {
		try {
			$rows = $objectService->findAll(
				config: ['filters' => $conversation + ['register' => $this->getRegisterSlug(), 'schema' => $schema]],
				_rbac: ($scope['_rbac'] ?? true),
				_multitenancy: ($scope['_multitenancy'] ?? true),
			);
		} catch (Throwable $e) {
			$this->logConversationFailure(message: self::CONVERSATION_LOOKUP_FAILURE, error: $e);
			$rows = [];
		}

		if (is_array($rows) === true && $rows !== []) {
			return $this->extractId(payload: $this->toArray(value: $rows[0]));
		}

		return null;
	}//end findOpenConversation()

	/**
	 * Open a new conversation stamped on the host's CONVERSATION_OPENING_STAMPS, returning its id or '' when the save fails.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param array<string, string> $conversation The contact, provider, channel and open status.
	 * @param string $schema The conversation schema slug.
	 *
	 * @return string The new conversation id.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function openConversation(object $objectService, array $conversation, string $schema): string {
		try {
			$object = $conversation + array_fill_keys(self::CONVERSATION_OPENING_STAMPS, $this->nowIso());
			$saved = $objectService->saveObject(object: $object, register: $this->getRegisterSlug(), schema: $schema, uuid: null);
		} catch (Throwable $e) {
			$this->logConversationFailure(message: 'open failed', error: $e);
			return '';
		}

		return $this->extractId(payload: $this->toArray(value: $saved));
	}//end openConversation()

	/**
	 * Log a findOrOpenConversation failure under the host's short class name; an empty message logs nothing.
	 *
	 * @param string $message The failure description.
	 * @param Throwable $error The caught error.
	 *
	 * @return void
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function logConversationFailure(string $message, Throwable $error): void {
		if ($message === '') {
			return;
		}

		$this->logger->warning(
			substr(strrchr(self::class, '\\'), 1).'.findOrOpenConversation: '.$message,
			['exception' => $error->getMessage()]
		);
	}//end logConversationFailure()
}//end trait
