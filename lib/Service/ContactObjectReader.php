<?php

/**
 * Pipelinq ContactObjectReader.
 *
 * Reads a Pipelinq client or contact from its register for a contacts sync.
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
use OCA\Pipelinq\Util\ResultToArray;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Reads a client or contact object as an array.
 *
 * @spec openspec/specs/contacts-sync/spec.md
 */
class ContactObjectReader {
	use ResultToArray;

	/**
	 * Allowed object types for vCard sync.
	 *
	 * Only these values may be used to construct an IAppConfig key, preventing
	 * an unvalidated caller-supplied $objectType from reaching arbitrary keys.
	 *
	 * @var string[]
	 */
	public const OBJECT_TYPES = ['client', 'contact'];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config.
	 * @param RegisterResolverService $registerResolver The register resolver.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private RegisterResolverService $registerResolver,
		private readonly ObjectServiceInterface $objectService,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Fetch a Pipelinq object by type and ID.
	 *
	 * @param string $objectType The object type (client or contact).
	 * @param string $objectId The object ID.
	 *
	 * @return ?array The serialized object data or null on failure.
	 *
	 * @spec openspec/specs/contacts-sync/spec.md
	 */
	public function fetch(string $objectType, string $objectId): ?array {
		if (in_array($objectType, self::OBJECT_TYPES, true) === false) {
			$this->logger->warning('ContactVcardService: invalid objectType', ['objectType' => $objectType]);
			return null;
		}

		$registerId = $this->registerResolver->resolve('contact');
		$schemaId = $this->appConfig->getValueString(AppIdentity::APP_ID, "{$objectType}_schema", '');

		if ($registerId === '' || $schemaId === '') {
			$this->logger->warning('Pipelinq: Cannot sync -- register or schema not configured');
			return null;
		}

		try {
			$object = $this->objectService->find(
				$objectId,
				[],
				false,
				$registerId,
				$schemaId
			);
		} catch (\Exception $e) {
			$this->logger->error('Pipelinq: Failed to fetch object for sync', ['exception' => $e->getMessage()]);
			return null;
		}

		return $this->serializeResult(result: $object);
	}//end fetch()
}//end class
