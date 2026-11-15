<?php

/**
 * Pipelinq SlaTrackedObjectStore.
 *
 * Read, write and type steps shared by the SLA object listeners.
 *
 * @category Util
 * @package  OCA\Pipelinq\Listener\Support
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

namespace OCA\Pipelinq\Listener\Support;

use OCA\Pipelinq\Kernel\AppIdentity;
use Throwable;

/**
 * Resolves an object's SLA type and re-reads or saves it through the
 * container's ObjectService against the configured register, logging failures
 * prefixed with the host class's short name. The host class provides
 * IAppConfig `$appConfig`, ContainerInterface `$container` and LoggerInterface
 * `$logger` properties and a TICKET_TYPE_LABELS constant.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait SlaTrackedObjectStore {

	/**
	 * Resolve the SLA type of an object, or null when it is not SLA-tracked.
	 *
	 * @param string $entityType Entity type the object's schema maps to.
	 * @param array<string, mixed> $data Object data (for the ticket discriminator).
	 *
	 * @return string|null The SLA type (`request` / `complaint`), or null.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function resolveSlaType(string $entityType, array $data): ?string {
		if ($entityType !== 'ticket') {
			return null;
		}

		return (self::TICKET_TYPE_LABELS[(string)($data['ticketType'] ?? '')] ?? null);
	}//end resolveSlaType()

	/**
	 * Read the tracked object's current data.
	 *
	 * @param string $uuid Object UUID.
	 * @param string $schemaId Schema identity the object lives in.
	 *
	 * @return array<string, mixed>|null Object data, or null when it is gone.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function fetch(string $uuid, string $schemaId): ?array {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		if ($register === '') {
			return null;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$object = $objectService->find(id: $uuid, register: $register, schema: $schemaId);
			if ($object === null) {
				return null;
			}

			return $object->getObject();
		} catch (Throwable $e) {
			$this->logger->warning(
				substr(strrchr(self::class, '\\'), 1).': re-read failed (non-blocking)',
				['error' => $e->getMessage(), 'uuid' => $uuid]
			);
			return null;
		}//end try
	}//end fetch()

	/**
	 * Persist the mutated object data back to OpenRegister.
	 *
	 * Uses OR's `saveObject()` to atomically write the slaStatus
	 * envelope. Failure is logged but never thrown (REQ-007).
	 *
	 * @param string $uuid Object UUID.
	 * @param string $schemaId Schema identity the object lives in.
	 * @param array<string, mixed> $data Mutated data including slaStatus.
	 *
	 * @return void
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function persist(string $uuid, string $schemaId, array $data): void {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		if ($register === '' || $schemaId === '' || $uuid === '') {
			return;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$objectService->saveObject(
				object: $data,
				extend: [],
				register: $register,
				schema: $schemaId,
				uuid: $uuid,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				substr(strrchr(self::class, '\\'), 1).': persist failed (non-blocking)',
				['error' => $e->getMessage(), 'uuid' => $uuid]
			);
		}//end try
	}//end persist()
}//end trait
