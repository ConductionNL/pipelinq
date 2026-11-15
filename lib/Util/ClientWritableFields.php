<?php

/**
 * Pipelinq ClientWritableFields.
 *
 * The client-writable subset of a request payload.
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

/**
 * Keeps only the payload keys listed in the host's CLIENT_WRITABLE constant.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait ClientWritableFields {

	/**
	 * Take only the fields a client may set.
	 *
	 * @param array<string, mixed> $payload The client's payload.
	 *
	 * @return array<string, mixed> The accepted subset.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function clientFields(array $payload): array {
		$out = [];
		foreach (self::CLIENT_WRITABLE as $field) {
			if (array_key_exists($field, $payload) === true) {
				$out[$field] = $payload[$field];
			}
		}

		return $out;
	}//end clientFields()
}//end trait
