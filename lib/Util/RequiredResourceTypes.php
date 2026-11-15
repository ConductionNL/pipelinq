<?php

/**
 * Pipelinq RequiredResourceTypes.
 *
 * The resource types an appointment service requires.
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
 * The service's requiredResourceTypes list, or an empty list when it is
 * missing or not a list.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait RequiredResourceTypes {

	/**
	 * The resource types a Service requires; empty means any type.
	 *
	 * @param array<string, mixed> $service The Service.
	 *
	 * @return array<int|string, mixed>
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function requiredResourceTypes(array $service): array {
		$requiredTypes = ($service['requiredResourceTypes'] ?? []);
		if (is_array($requiredTypes) === false) {
			return [];
		}

		return $requiredTypes;
	}//end requiredResourceTypes()
}//end trait
