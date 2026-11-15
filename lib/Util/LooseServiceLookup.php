<?php

/**
 * Pipelinq LooseServiceLookup.
 *
 * Resolves an optional service from the container without importing its class.
 *
 * @category Util
 * @package  OCA\Pipelinq\Util
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

namespace OCA\Pipelinq\Util;

use Throwable;

/**
 * Looks a service up by id, so the host loads cleanly whether or not the app
 * providing it is installed. The host class provides a ContainerInterface
 * `$container` property.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait LooseServiceLookup {

	/**
	 * The service, or null when the lookup fails, yields no object, or the
	 * object lacks the required method.
	 *
	 * @param string $serviceId The container id.
	 * @param string $method    The method the service must have; '' requires none.
	 *
	 * @return object|null
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function looseService(string $serviceId, string $method): ?object {
		try {
			$service = $this->container->get($serviceId);
		} catch (Throwable $e) {
			return null;
		}

		if (is_object($service) === false || ($method !== '' && method_exists($service, $method) === false)) {
			return null;
		}

		return $service;
	}//end looseService()
}//end trait
