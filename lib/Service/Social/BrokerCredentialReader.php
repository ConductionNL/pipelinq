<?php

/**
 * Pipelinq BrokerCredentialReader.
 *
 * Reads the METADATA of a brokered credential: its status, its provider, its
 * owner, its scopes and when it expires. It never reads a secret, and it could
 * not: the token, the refresh token and the client secret live in keepiq, and
 * OpenRegister's broker hands them to nobody, including this class.
 *
 * The register and schema names come from `CredentialBrokerService` itself
 * (`REGISTER` and `SCHEMA`), which documents reading a credential's status
 * exactly this way. They are restated as constants here so an instance without
 * OpenRegister does not need the class to be loadable to answer "no".
 *
 * RBAC IS LEFT ON, ON PURPOSE. Every other register read in this app runs with
 * `_rbac: false`, because the marketing endpoints have no session and an
 * anonymous read silently returns nothing. These rows are somebody else's
 * credentials, so the opposite rule applies: the read is scoped to the caller
 * and a row they may not see must not come back.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Social
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
 *
 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Social;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Metadata-only reads of a brokered credential.
 *
 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
 */
class BrokerCredentialReader {
	/**
	 * The broker's own register, restated from `CredentialBrokerService::REGISTER`.
	 *
	 * @var string
	 */
	public const REGISTER = 'credential-broker';

	/**
	 * The broker's own schema, restated from `CredentialBrokerService::SCHEMA`.
	 *
	 * @var string
	 */
	public const SCHEMA = 'brokeredcredential';

	/**
	 * The properties a credential row may hand to Pipelinq. Anything not named
	 * here is dropped, so a broker that one day serialised more could not leak
	 * it through this class by accident.
	 *
	 * @var array<int, string>
	 */
	public const READABLE = [
		'id',
		'uuid',
		'name',
		'provider',
		'kind',
		'status',
		'owner',
		'scope',
		'scopes',
		'expiresAt',
		'lastError',
		'lastRefreshedAt',
		'accountHandle',
		'accountDisplayName',
		'accountId',
		'instanceBaseUrl',
		'clientId',
		'allowedApps',
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container, for the lazy ObjectService resolve.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * One credential's readable metadata, or null when it does not exist, the
	 * caller may not see it, or OpenRegister is absent.
	 *
	 * @param string $credentialRef The credential UUID.
	 *
	 * @return array<string, mixed>|null The readable metadata, or null.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
	 */
	public function read(string $credentialRef): ?array {
		if (trim($credentialRef) === '') {
			return null;
		}

		try {
			$service = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$entity = $service->find(
				id: $credentialRef,
				register: self::REGISTER,
				schema: self::SCHEMA,
			);
		} catch (Throwable $failure) {
			$this->logger->info(
				'BrokerCredentialReader.read: the credential could not be read',
				['exception' => $failure->getMessage()]
			);

			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $this->readable(entity: $entity);
	}//end read()

	/**
	 * The status a credential holds, in the broker's own vocabulary, or an
	 * empty string when it cannot be read.
	 *
	 * @param string $credentialRef The credential UUID.
	 *
	 * @return string The status, or an empty string.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
	 */
	public function status(string $credentialRef): string {
		$row = $this->read(credentialRef: $credentialRef);
		if ($row === null) {
			return '';
		}

		return (string)($row['status'] ?? '');
	}//end status()

	/**
	 * The most recently connected credential for one provider that the given
	 * user owns, or null when there is none.
	 *
	 * This is what the connect flow needs on the way back. OpenRegister's
	 * callback redirects to `?connected=ok` and does NOT name the credential it
	 * minted, so the account it belongs to is resolved here: the newest
	 * credential for that provider, owned by the person who just connected. The
	 * read is scoped to that owner, so a second user's credential can never be
	 * the answer even when it is newer.
	 *
	 * @param string $provider The catalogue provider identifier.
	 * @param string $ownerUid The user who connected.
	 *
	 * @return array<string, mixed>|null The readable metadata, or null.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
	 */
	public function findLatest(string $provider, string $ownerUid): ?array {
		if (trim($provider) === '' || trim($ownerUid) === '') {
			return null;
		}

		try {
			$service = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$rows = $service->findAll(
				config: [
					'filters' => [
						'register' => self::REGISTER,
						'schema' => self::SCHEMA,
						'provider' => $provider,
					],
				],
			);
		} catch (Throwable $failure) {
			$this->logger->info(
				'BrokerCredentialReader.findLatest: the credential list could not be read',
				['provider' => $provider, 'exception' => $failure->getMessage()]
			);

			return null;
		}

		$best = null;
		foreach (($rows ?? []) as $row) {
			$readable = $this->readable(entity: $row);
			if ($this->belongsTo(row: $readable, provider: $provider, ownerUid: $ownerUid) === false) {
				continue;
			}

			if ($best === null || $this->connectedAt(row: $readable) >= $this->connectedAt(row: $best)) {
				$best = $readable;
			}
		}

		return $best;
	}//end findLatest()

	/**
	 * Whether a credential row really is this provider's and this owner's.
	 *
	 * Both filters are re-checked in PHP. OpenRegister's filter DSL ignores a
	 * key it does not recognise, and an ignored filter returns rows nobody
	 * asked for while looking exactly like a correct answer.
	 *
	 * @param array<string, mixed> $row The credential metadata.
	 * @param string $provider The provider that was asked for.
	 * @param string $ownerUid The owner that was asked for.
	 *
	 * @return bool True when both match.
	 */
	private function belongsTo(array $row, string $provider, string $ownerUid): bool {
		if ($row === [] || (string)($row['provider'] ?? '') !== $provider) {
			return false;
		}

		return ((string)($row['owner'] ?? '') === $ownerUid);
	}//end belongsTo()

	/**
	 * When a credential was last refreshed or, failing that, when it expires.
	 * Used only to order candidates, so an unreadable value sorts oldest.
	 *
	 * @param array<string, mixed> $row The credential metadata.
	 *
	 * @return string A comparable ISO 8601 instant, or an empty string.
	 */
	private function connectedAt(array $row): string {
		foreach (['lastRefreshedAt', 'expiresAt'] as $field) {
			$value = trim((string)($row[$field] ?? ''));
			if ($value !== '') {
				return $value;
			}
		}

		return '';
	}//end connectedAt()

	/**
	 * Reduce a credential entity to the properties Pipelinq may read.
	 *
	 * @param mixed $entity The entity or array the register answered with.
	 *
	 * @return array<string, mixed> The readable subset.
	 */
	private function readable(mixed $entity): array {
		$payload = $entity;
		if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {
			$payload = $entity->jsonSerialize();
		}

		if (is_array($payload) === false) {
			return [];
		}

		$out = [];
		foreach (self::READABLE as $key) {
			if (array_key_exists($key, $payload) === true) {
				$out[$key] = $payload[$key];
			}
		}

		if (isset($payload['@self']) === true && is_array($payload['@self']) === true) {
			$out['id'] = (string)($payload['@self']['id'] ?? ($out['id'] ?? ''));
		}

		return $out;
	}//end readable()
}//end class
