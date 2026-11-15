<?php

/**
 * Pipelinq ClientRecordRead.
 *
 * Reads a client record as the caller.
 *
 * @category Service
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
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Support;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Service\RegisterSchema;

/**
 * Locates the client schema and reads one client under the caller's RBAC and
 * organisation. The host class provides IAppConfig `$appConfig` and
 * ContainerInterface `$container` properties.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait ClientRecordRead {

	/**
	 * The register and client schema, or null when the client id, register or schema is empty.
	 *
	 * @param string $clientId The client uuid.
	 *
	 * @return RegisterSchema|null
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function clientLocation(string $clientId): ?RegisterSchema {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'client_schema', '');
		if ($clientId === '' || $register === '' || $schema === '') {
			return null;
		}

		return new RegisterSchema(register: $register, schema: $schema);
	}//end clientLocation()

	/**
	 * Find the client with RBAC and multitenancy on; what the ObjectService throws is not caught.
	 *
	 * @param string         $clientId The client uuid.
	 * @param RegisterSchema $location The register and client schema.
	 *
	 * @return mixed The client object or null.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function findClientAsCaller(string $clientId, RegisterSchema $location): mixed {
		return $this->container->get('OCA\OpenRegister\Service\ObjectService')->find(
			id: $clientId,
			register: $location->register,
			schema: $location->schema,
			_rbac: true,
			_multitenancy: true,
		);
	}//end findClientAsCaller()
}//end trait
