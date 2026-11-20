<?php

/**
 * Pipelinq LinkedContactRecords.
 *
 * Reads the clients and contact persons that are linked to a Nextcloud
 * contact, for the contacts sync status and its full run.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
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
 * @spec openspec/specs/contacts-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Util\SerialisedToArray;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * The records with a `contactsUid`, read per type from the configured register.
 *
 * @spec openspec/specs/contacts-sync/spec.md
 */
class LinkedContactRecords {
	use SerialisedToArray;


	/**
	 * The most linked records one run writes back.
	 *
	 * @var int
	 */
	private const RUN_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig             $appConfig     The app config (register and schema ids).
	 * @param ObjectServiceInterface $objectService Reads the linked records.
	 * @param LoggerInterface        $logger        The logger.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The clients and contact persons linked to a Nextcloud contact.
	 *
	 * @return array<int, array{type: string, id: string, name: string}> The records.
	 *
	 * @spec openspec/specs/contacts-sync/spec.md
	 */
	public function all(): array {
		$registerId = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		if ($registerId === '') {
			return [];
		}

		$records = [];
		foreach (['client', 'contact'] as $type) {
			$records = array_merge($records, $this->linkedOfType(registerId: $registerId, type: $type));
		}

		return $records;
	}//end all()

	/**
	 * The linked records of one type; none when the type has no schema
	 * configured or its read fails (logged).
	 *
	 * @param string $registerId The register id.
	 * @param string $type       `client` or `contact`.
	 *
	 * @return array<int, array{type: string, id: string, name: string}>
	 */
	private function linkedOfType(string $registerId, string $type): array {
		$schemaId = $this->appConfig->getValueString(AppIdentity::APP_ID, $type . '_schema', '');
		if ($schemaId === '') {
			return [];
		}

		$objects = $this->objectsIn(self: ['register' => $registerId, 'schema' => $schemaId]);
		if ($objects === null) {
			return [];
		}

		return $this->linked(objects: $objects, type: $type);
	}//end linkedOfType()

	/**
	 * The objects of one register and schema, up to the run limit; null when
	 * the read fails (logged).
	 *
	 * @param array{register: string, schema: string} $self The `@self` filter.
	 *
	 * @return array<mixed>|null
	 */
	private function objectsIn(array $self): ?array {
		try {
			return $this->objectService->findAll(config: ['filters' => ['@self' => $self], 'limit' => self::RUN_LIMIT]);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: linked records not read', ['exception' => $e->getMessage()]);
			return null;
		}
	}//end objectsIn()

	/**
	 * The objects that carry a `contactsUid`, as records of the type.
	 *
	 * @param array<mixed> $objects The objects read.
	 * @param string       $type    `client` or `contact`.
	 *
	 * @return array<int, array{type: string, id: string, name: string}>
	 */
	private function linked(array $objects, string $type): array {
		$records = [];
		foreach ($objects as $object) {
			$data = $this->toArray(value: $object);
			if (empty($data['contactsUid']) === true) {
				continue;
			}

			$records[] = [
				'type' => $type,
				'id' => (string)($data['id'] ?? ($data['@self']['id'] ?? '')),
				'name' => (string)($data['name'] ?? ''),
			];
		}

		return $records;
	}//end linked()
}//end class
