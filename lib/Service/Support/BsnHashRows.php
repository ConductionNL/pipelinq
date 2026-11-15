<?php

/**
 * Pipelinq BsnHashRows.
 *
 * The rows of the host's schema for a raw BSN, looked up by its hash.
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

use OCA\Pipelinq\Service\BsnValidationService;

/**
 * Hashes the BSN in-process and queries the [register, schema] the host's
 * config() resolves by that hash. The host class provides config() and
 * getObjectService() steps.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait BsnHashRows {

	/**
	 * The rows for a raw BSN, looked up by its hash.
	 *
	 * @param string $rawBsn Raw BSN — hashed in-process only.
	 *
	 * @return mixed The findAll answer.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) BsnValidationService::hash is a pure stateless helper.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function rowsForBsn(#[\SensitiveParameter] string $rawBsn): mixed {
		[$register, $schema] = $this->config();
		$hash = BsnValidationService::hash($rawBsn);
		return $this->getObjectService()->findAll(
			config: [
				'filters' => [
					'bsnHash' => $hash,
					'register' => $register,
					'schema' => $schema,
				],
			]
		);
	}//end rowsForBsn()
}//end trait
