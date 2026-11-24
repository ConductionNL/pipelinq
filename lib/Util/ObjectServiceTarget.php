<?php

/**
 * Pipelinq ObjectServiceTarget.
 *
 * The register, schema and ObjectService to work through, or null.
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
 * Answers [register, schema, ObjectService] when both slugs are set and the
 * host's getObjectService() supplies a service, and null otherwise.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait ObjectServiceTarget {

	/**
	 * The register, schema and ObjectService to read or write through, or null when one is missing.
	 *
	 * @param string $register The register slug.
	 * @param string $schema   The schema slug.
	 *
	 * @return array{0: string, 1: string, 2: object}|null
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function objectTarget(string $register, string $schema): array|null {
		if ($register === '' || $schema === '') {
			return null;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		return [$register, $schema, $objectService];
	}//end objectTarget()
}//end trait
