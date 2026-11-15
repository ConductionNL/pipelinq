<?php

/**
 * Pipelinq ConfiguredSchemaObjectRead.
 *
 * Re-read one object of the host's configured schema.
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

use OCA\Pipelinq\Kernel\AppIdentity;

/**
 * Reads one object from the container's ObjectService against the configured
 * register and the schema under the host's OBJECT_SCHEMA_KEY, logging
 * FETCH_FAILURE_MESSAGE on failure. The host class provides IAppConfig
 * `$appConfig`, ContainerInterface `$container` and LoggerInterface `$logger`.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait ConfiguredSchemaObjectRead {
	use ContainerObjectFind;

	/**
	 * Re-read an object's current data.
	 *
	 * @param string $uuid Object UUID.
	 *
	 * @return array<string, mixed>|null Object data, or null when unconfigured, gone or unreadable.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function fetch(string $uuid): ?array {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(AppIdentity::APP_ID, self::OBJECT_SCHEMA_KEY, '');
		if ($register === '' || $schema === '' || $uuid === '') {
			return null;
		}

		return $this->findObjectData(register: $register, schema: $schema, uuid: $uuid);
	}//end fetch()
}//end trait
