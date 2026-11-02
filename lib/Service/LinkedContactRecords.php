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

use OCA\Pipelinq\AppInfo\Application;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * The records with a `contactsUid`, read per type from the configured register.
 *
 * @spec openspec/specs/contacts-sync/spec.md
 */
class LinkedContactRecords {

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
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($registerId === '') {
			return [];
		}

		$records = [];
		foreach (['client', 'contact'] as $type) {
			$schemaId = $this->appConfig->getValueString(Application::APP_ID, $type . '_schema', '');
			if ($schemaId === '') {
				continue;
			}

			try {
				$objects = $this->objectService->findAll(
					config: [
						'filters' => ['@self' => ['register' => $registerId, 'schema' => $schemaId]],
						'limit' => self::RUN_LIMIT,
					]
				);
			} catch (\Throwable $e) {
				$this->logger->warning('Pipelinq: linked records not read', ['exception' => $e->getMessage()]);
				continue;
			}

			foreach ($objects as $object) {
				$data = $this->toArray(object: $object);
				if (empty($data['contactsUid']) === true) {
					continue;
				}

				$records[] = [
					'type' => $type,
					'id' => (string)($data['id'] ?? ($data['@self']['id'] ?? '')),
					'name' => (string)($data['name'] ?? ''),
				];
			}
		}//end foreach

		return $records;
	}//end all()

	/**
	 * An OpenRegister result as an array.
	 *
	 * @param mixed $object The result.
	 *
	 * @return array<string, mixed> The data.
	 */
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$data = $object->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return [];
	}//end toArray()
}//end class
