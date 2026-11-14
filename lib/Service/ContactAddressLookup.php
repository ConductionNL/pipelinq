<?php

/**
 * Pipelinq ContactAddressLookup.
 *
 * Finds the address integriq is asked about for a pipelinq contact on one
 * channel: the email for email, the phone for SMS and WhatsApp. integriq
 * normalises it; pipelinq sends it as stored.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-asks-integriq-before-every-non-exempt-message-req-cii-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Util\SerialisedToArray;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Contact id plus channel in, address out.
 *
 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-asks-integriq-before-every-non-exempt-message-req-cii-002
 */
class ContactAddressLookup {
	use SerialisedToArray;


	/**
	 * The phone fields, in the order the adapters read them.
	 *
	 * @var array<int,string>
	 */
	private const PHONE_KEYS = ['phoneNumber', 'mobile', 'telephone', 'phone'];

	/**
	 * The email fields.
	 *
	 * @var array<int,string>
	 */
	private const EMAIL_KEYS = ['email', 'emailAddress'];

	/**
	 * Addresses already looked up in this request.
	 *
	 * @var array<string,string>
	 */
	private array $cache = [];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's ObjectService.
	 * @param IAppConfig         $appConfig Holds the register and schema slugs.
	 * @param LoggerInterface    $logger    Logs a failed read.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The address of one contact on one channel.
	 *
	 * A consent record can carry an email address as its contact id (a public
	 * list signup); that address is the answer for email.
	 *
	 * @param string $contactId The contact or client UUID, or an email address.
	 * @param string $channel   `email`, `sms` or `whatsapp`.
	 *
	 * @return string The address, or empty when the contact has none.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-asks-integriq-before-every-non-exempt-message-req-cii-002
	 */
	public function addressFor(string $contactId, string $channel): string {
		$contactId = trim($contactId);
		if ($contactId === '') {
			return '';
		}

		if (str_contains($contactId, '@') === true) {
			if ($channel === 'email') {
				return strtolower($contactId);
			}

			return '';
		}

		$cacheKey = $contactId.'|'.$channel;
		if (isset($this->cache[$cacheKey]) === true) {
			return $this->cache[$cacheKey];
		}

		$contact = $this->loadContact(contactId: $contactId);
		$address = '';
		if ($contact !== null) {
			$address = self::fromContact(contact: $contact, channel: $channel);
		}

		$this->cache[$cacheKey] = $address;
		return $address;
	}//end addressFor()

	/**
	 * The address of a contact row on one channel.
	 *
	 * @param array<string,mixed> $contact The contact row.
	 * @param string              $channel `email`, `sms` or `whatsapp`.
	 *
	 * @return string The address, or empty.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-asks-integriq-before-every-non-exempt-message-req-cii-002
	 */
	public static function fromContact(array $contact, string $channel): string {
		$keys = self::PHONE_KEYS;
		if ($channel === 'email') {
			$keys = self::EMAIL_KEYS;
		}

		foreach ($keys as $key) {
			$value = ($contact[$key] ?? null);
			if (is_string($value) === true && trim($value) !== '') {
				return trim($value);
			}
		}

		return '';
	}//end fromContact()

	/**
	 * Load a contact, then a client, by id.
	 *
	 * @param string $contactId The UUID.
	 *
	 * @return array<string,mixed>|null The row.
	 */
	private function loadContact(string $contactId): ?array {
		$resolved = $this->resolveObjectService();
		if ($resolved === []) {
			return null;
		}

		$register = $this->configuredOr(key: 'register', default: 'pipelinq');

		foreach (['contact_schema' => 'contact', 'client_schema' => 'client'] as $key => $default) {
			$schema = $this->configuredOr(key: $key, default: $default);
			$row = $this->rowInSchema(objectService: $resolved[0], contactId: $contactId, register: $register, schema: $schema);
			if ($row !== []) {
				return $row;
			}
		}

		return null;
	}//end loadContact()

	/**
	 * OpenRegister's ObjectService.
	 *
	 * @return array<int, mixed> The service as a one-element list, or an empty list when unavailable.
	 */
	private function resolveObjectService(): array {
		try {
			return [$this->container->get('OCA\\OpenRegister\\Service\\ObjectService')];
		} catch (Throwable $e) {
			return [];
		}
	}//end resolveObjectService()

	/**
	 * An app-config value, or the default when it is unset.
	 *
	 * @param string $key The app-config key.
	 * @param string $default The default.
	 *
	 * @return string
	 */
	private function configuredOr(string $key, string $default): string {
		$value = $this->appConfig->getValueString(AppIdentity::APP_ID, $key, '');
		if ($value === '') {
			$value = $default;
		}

		return $value;
	}//end configuredOr()

	/**
	 * The contact's row in one schema; empty when it is not found there.
	 *
	 * @param mixed $objectService OpenRegister's ObjectService.
	 * @param string $contactId The UUID.
	 * @param string $register The register id.
	 * @param string $schema The schema id.
	 *
	 * @return array<string,mixed>
	 */
	private function rowInSchema($objectService, string $contactId, string $register, string $schema): array {
		try {
			$entity = $objectService->find(id: $contactId, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->debug('ContactAddressLookup: not found in '.$schema, ['contactId' => $contactId]);
			$entity = null;
		}

		return $this->toArray(value: $entity);
	}//end rowInSchema()
}//end class
