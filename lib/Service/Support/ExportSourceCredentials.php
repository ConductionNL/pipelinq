<?php

/**
 * Pipelinq ExportSourceCredentials.
 *
 * The secret fields of an export destination's connector source.
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


use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
/**
 * Resolves a destination's connector source and collects its legacy secret
 * fields and configured authentication block. The host class provides
 * ConnectorSourceRegister `$connectorRegister` and LoggerInterface `$logger`
 * properties, getObjectService() and toArray() steps, and
 * OPENCONNECTOR_LEGACY_SECRET_FIELDS, OPENCONNECTOR_SOURCE_SCHEMA_SLUG and
 * CREDENTIALS_FAILURE_MESSAGE constants.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait ExportSourceCredentials {

	/**
	 * Extract the legacy write-only credential fields (and any non-secret
	 * broker authentication config) from a raw OpenConnector Source.
	 *
	 * @param array<string, mixed> $source The raw (unrendered) Source object.
	 *
	 * @return array<string, mixed> The extracted credentials.
	 *
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#REQ-BIE-010
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function extractSourceCredentials(array $source): array {
		$credentials = [];
		foreach (self::OPENCONNECTOR_LEGACY_SECRET_FIELDS as $field) {
			$value = $source[$field] ?? null;
			if (is_string($value) === true && $value !== '') {
				$credentials[$field] = $value;
			}
		}

		$authentication = $source['configuration']['authentication'] ?? null;
		if (is_array($authentication) === true && $authentication !== []) {
			$credentials['authentication'] = $authentication;
		}

		return $credentials;
	}//end extractSourceCredentials()

	/**
	 * Resolve credentials for the destination's OpenConnector source.
	 *
	 * `OCA\OpenConnector\Service\SourceService` — the class this used to
	 * resolve credentials through — no longer exists; Source objects moved
	 * onto OpenRegister's generic object API (register `openconnector`,
	 * schema `source`). A RENDERED read strips every write-only secret field
	 * unconditionally (admins included); only `_render: false` survives that
	 * boundary — the same raw re-read OpenConnector's own CallService /
	 * RawSourceResolver use internally (ocon#242). `_rbac`/`_multitenancy`
	 * stay true, so this is access-neutral, not a widened read.
	 *
	 * Never returns the credentials to a client — they are consumed only inside
	 * the sink adapter. When OC is unavailable an empty map is returned and the
	 * upload fails closed.
	 *
	 * @param array<string, mixed> $destination The destination configuration.
	 *
	 * @return array<string, mixed> The resolved credentials (empty when unavailable).
	 *
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#REQ-BIE-010
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function resolveCredentials(array $destination): array {
		$sourceId = (string)($destination['connectorSourceId'] ?? '');
		if ($sourceId === '') {
			return [];
		}

		// The register is asked for, never assumed. Reading with a slug this
		// instance does not carry returns no source, and this method's empty
		// return then reads as "no credentials" — the wrong diagnosis for a
		// register that is not there. ConnectorSourceRegister logs which it was.
		$registerSlug = $this->connectorRegister->slugOrNull(
			operation: substr(strrchr(self::class, '\\'), 1).'.resolveCredentials',
			sourceId: $sourceId
		);
		if ($registerSlug === null) {
			return [];
		}

		try {
			$source = $this->getObjectService()->find(
				id: $sourceId,
				options: new ObjectFindOptions(
					register: $registerSlug,
					schema: self::OPENCONNECTOR_SOURCE_SCHEMA_SLUG,
					render: false
				)
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				self::CREDENTIALS_FAILURE_MESSAGE,
				['sourceId' => $sourceId, 'error' => $e->getMessage()]
			);
			return [];
		}

		if ($source === null) {
			return [];
		}

		return $this->extractSourceCredentials(source: $this->toArray(object: $source));
	}//end resolveCredentials()
}//end trait
