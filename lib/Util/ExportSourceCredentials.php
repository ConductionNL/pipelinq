<?php

/**
 * Pipelinq ExportSourceCredentials.
 *
 * The secret fields of an export destination's connector source.
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
 * Collects the legacy secret fields and the configured authentication block of
 * a connector source. The host class provides an
 * OPENCONNECTOR_LEGACY_SECRET_FIELDS constant.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait ExportSourceCredentials {

	/**
	 * Extract the legacy write-only credential fields (and any non-secret
	 * broker authentication config) from a raw OpenConnector Source.
	 *
	 * @param array<string, mixed> $source The raw (unrendered) Source object.
	 *
	 * @return array<string, mixed> The extracted credentials.
	 *
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#REQ-BIE-010
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function extractSourceCredentials(array $source): array {
		$credentials = [];
		foreach (self::OPENCONNECTOR_LEGACY_SECRET_FIELDS as $field) {
			$value = $source[$field] ?? null;
			if (is_string($value) === true && $value !== '') {
				$credentials[$field] = $value;
			}
		}

		$authentication = $source['configuration']['authentication'] ?? null;
		if (is_array($authentication) === true && $authentication !== []) {
			$credentials['authentication'] = $authentication;
		}

		return $credentials;
	}//end extractSourceCredentials()
}//end trait
