<?php

/**
 * Pipelinq JsonConfigArray.
 *
 * Reads a JSON-encoded array from this app's config.
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
 * Decodes a JSON array kept under one app-config key. The host class provides
 * an IAppConfig `$appConfig` property.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait JsonConfigArray {

	/**
	 * The decoded array under the key; empty when unset or not a JSON array.
	 *
	 * @param string $key The app-config key.
	 *
	 * @return array<mixed>
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function jsonConfigArray(string $key): array {
		$raw = $this->appConfig->getValueString(AppIdentity::APP_ID, $key, '');
		if ($raw === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end jsonConfigArray()
}//end trait
