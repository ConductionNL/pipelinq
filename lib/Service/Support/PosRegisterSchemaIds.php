<?php

/**
 * Pipelinq PosRegisterSchemaIds.
 *
 * The configured POS [register, schema] ids, or an OCS not-found error.
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

use OCA\Pipelinq\Kernel\AppIdentity;
use OCP\AppFramework\OCS\OCSNotFoundException;

/**
 * Reads 'register' and a schema key from app config and throws
 * OCSNotFoundException when either is empty. The host class provides an
 * IAppConfig `$appConfig` property.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait PosRegisterSchemaIds {

	/**
	 * Resolve the register + a schema config key into their stored IDs.
	 *
	 * @param string $schemaKey The app-config schema key.
	 *
	 * @return array{0: string, 1: string} The [register, schema] IDs.
	 *
	 * @throws OCSNotFoundException If the register or schema is not configured.
	 *
	 * @spec openspec/changes/archive/2026-06-14-pos-staff-pin-permissions/tasks.md#2.1
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function config(string $schemaKey): array {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(AppIdentity::APP_ID, $schemaKey, '');

		if ($register === '' || $schema === '') {
			throw new OCSNotFoundException('POS register of schema is niet geconfigureerd.');
		}

		return [$register, $schema];
	}//end config()
}//end trait
