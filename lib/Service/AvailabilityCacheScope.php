<?php

/**
 * Pipelinq AvailabilityCacheScope.
 *
 * Where the cached availability rows of one resource live.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/appointment-booking/spec.md#requirement-the-server-expires-cached-availability-when-a-resource-or-service-changes
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * The register, availability-cache schema and resource of a cache invalidation.
 *
 * @spec openspec/specs/appointment-booking/spec.md#requirement-the-server-expires-cached-availability-when-a-resource-or-service-changes
 */
final class AvailabilityCacheScope {
	/**
	 * Constructor.
	 *
	 * @param string $register   The register id.
	 * @param string $schema     The availability-cache schema id.
	 * @param string $resourceId Resource UUID.
	 */
	public function __construct(
		public readonly string $register,
		public readonly string $schema,
		public readonly string $resourceId,
	) {
	}//end __construct()
}//end class
