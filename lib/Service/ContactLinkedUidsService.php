<?php

/**
 * Pipelinq ContactLinkedUidsService.
 *
 * Service for finding already-linked contact UIDs in Pipelinq objects.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Service for finding already-linked contact UIDs.
 */
class ContactLinkedUidsService {
	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Get all contactsUid values from existing Pipelinq clients and contacts.
	 *
	 * @return array The linked contact UIDs.
	 * @spec   openspec/changes/reverse-2026-05-26-be-contact-comms/tasks.md#task-1
	 */
	public function getLinkedContactsUids(): array {
		$uids = [];
		$objectService = $this->getObjectService();
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');

		// Fail closed on an unconfigured register. getLinkedUidsForType() below
		// already refuses on '', so this returns the same empty result; stating
		// it here makes the refusal local to the read instead of resting on a
		// callee that a later change could relax.
		if ($registerId === '') {
			$this->logger->warning(
				'Pipelinq: app-config "register" is not configured; returning no linked contact UIDs'
			);
			return [];
		}

		foreach (['client', 'contact'] as $type) {
			$typeUids = $this->getLinkedUidsForType(
				objectService: $objectService,
				registerId: $registerId,
				type: $type
			);
			$uids = array_merge($uids, $typeUids);
		}//end foreach

		return $uids;
	}//end getLinkedContactsUids()

	/**
	 * Get linked contactsUid values for a specific object type.
	 *
	 * @param object $objectService The object service.
	 * @param string $registerId The register ID.
	 * @param string $type The object type (client or contact).
	 *
	 * @return array The linked UIDs for this type.
	 */
	private function getLinkedUidsForType(object $objectService, string $registerId, string $type): array {
		$schemaId = $this->appConfig->getValueString(Application::APP_ID, "{$type}_schema", '');
		if ($registerId === '' || $schemaId === '') {
			return [];
		}

		$uids = [];

		try {
			$objects = $objectService->findAll(
				['filters' => ['register' => $registerId, 'schema' => $schemaId], 'limit' => 500]
			);

			foreach ($objects as $obj) {
				$data = $this->serializeResult(result: $obj);
				if (empty($data['contactsUid']) === false) {
					$uids[] = $data['contactsUid'];
				}
			}
		} catch (\Exception $e) {
			$this->logger->debug(
				'Pipelinq: Failed to fetch linked UIDs for ' . $type,
				['exception' => $e->getMessage()]
			);
		}

		return $uids;
	}//end getLinkedUidsForType()

	/**
	 * Serialize an object or array result to an array.
	 *
	 * @param mixed $result The result to serialize.
	 *
	 * @return array The serialized result.
	 */
	private function serializeResult(mixed $result): array {
		if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {
			return $result->jsonSerialize();
		}

		if (is_array($result) === true) {
			return $result;
		}

		return [];
	}//end serializeResult()

	/**
	 * Get the OpenRegister ObjectService via the container.
	 *
	 * @return object The object service.
	 */
	private function getObjectService(): object {
		return $this->objectService;
	}//end getObjectService()
}//end class
