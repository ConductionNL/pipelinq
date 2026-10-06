<?php

/**
 * Pipelinq WritesAsMessagingAccount.
 *
 * Shared by the SMS and WhatsApp adapters: the part of a provider webhook
 * that writes runs as the messaging service account, with OpenRegister's
 * checks on, and answers serviceUnavailable (503) without writing anything
 * when there is no usable account. The sender's contact lookup lives here
 * too: a read without RBAC, never a write.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-005-consent-gating-and-recording
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs a webhook's writes as the messaging service account.
 *
 * The host class carries `$container` (ContainerInterface), `$logger`, the
 * LOOKUP_SCOPE and DEFAULT_CONTACT_SCHEMA_SLUG constants, and the
 * getObjectService(), getRegisterSlug(), resolveSchemaSlug(), extractId() and
 * toArray() helpers.
 *
 * @property ContainerInterface $container
 * @property LoggerInterface    $logger
 *
 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-005-consent-gating-and-recording
 */
trait WritesAsMessagingAccount {
	/**
	 * Run the webhook's writes as the messaging service account.
	 *
	 * @param callable(): array<string, mixed> $writes The writes; they answer the outcome envelope.
	 *
	 * @return array<string, mixed> The envelope, or `serviceUnavailable` when nothing ran.
	 */
	private function asMessagingAccount(callable $writes): array {
		try {
			$account = $this->container->get(MessagingServiceAccount::class);
		} catch (Throwable $e) {
			$account = null;
		}

		if (($account instanceof MessagingServiceAccount) === false) {
			$this->logger->error('Pipelinq messaging: no messaging service account resolves; the webhook writes nothing');
			return ['status' => 'serviceUnavailable'];
		}

		try {
			return $account->runAs(operation: $writes);
		} catch (ServiceAccountUnavailableException $e) {
			return ['status' => 'serviceUnavailable'];
		}
	}//end asMessagingAccount()

	/**
	 * The contact behind a phone number, '' when none: a read without RBAC (LOOKUP_SCOPE).
	 *
	 * @param string $phone Sender phone number (E.164).
	 *
	 * @return string Contact UUID, or '' when no contact has this number.
	 */
	private function findContactByPhone(string $phone): string {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return '';
		}

		try {
			$rows = $objectService->findAll(
				config: [
					'filters' => [
						// The contact schema stores the number under `phone`.
						'phone' => $phone,
						'register' => $this->getRegisterSlug(),
						'schema' => $this->resolveSchemaSlug(key: 'contact_schema', default: self::DEFAULT_CONTACT_SCHEMA_SLUG),
					],
				],
				_rbac: self::LOOKUP_SCOPE['_rbac'],
				_multitenancy: self::LOOKUP_SCOPE['_multitenancy'],
			);
		} catch (Throwable $e) {
			return '';
		}

		if (is_array($rows) === false || $rows === []) {
			return '';
		}

		return $this->extractId(payload: $this->toArray(value: $rows[0]));
	}//end findContactByPhone()
}//end trait
