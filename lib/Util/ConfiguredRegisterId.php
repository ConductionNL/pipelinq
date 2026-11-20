<?php

/**
 * Pipelinq ConfiguredRegisterId.
 *
 * The pipelinq register id from app config, failing closed when unset.
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
 * Reads the 'register' app-config value, logging a warning when it is empty,
 * and schema ids by app-config key. The host class provides IAppConfig
 * `$appConfig` and LoggerInterface `$logger` properties.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait ConfiguredRegisterId {

	/**
	 * The pipelinq register id from app config.
	 *
	 * Fails closed: '' means "unconfigured" and every caller refuses the
	 * OpenRegister call on it. An empty register is NOT the same as none —
	 * ObjectService skips setRegister() for an empty value, so the query
	 * inherits whatever context an earlier call in the request left behind.
	 *
	 * @return string The configured register id, or '' when unconfigured.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function registerId(): string {
		$registerId = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		if ($registerId === '') {
			$this->logger->warning(
				'Pipelinq: app-config "register" is not configured; OpenRegister calls are refused, not run unscoped'
			);
		}

		return $registerId;
	}//end registerId()

	/**
	 * Resolve a schema id by app-config key.
	 *
	 * @param string $key App-config key.
	 *
	 * @return string
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function schemaId(string $key): string {
		return $this->appConfig->getValueString(AppIdentity::APP_ID, $key, '');
	}//end schemaId()
}//end trait
