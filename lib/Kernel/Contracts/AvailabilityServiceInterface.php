<?php

/**
 * Contract of AvailabilityService
 *
 * The public surface of AvailabilityService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

/**
 * Contract of AvailabilityService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AvailabilityServiceInterface {

	/**
	 * App-config key for the Resource schema id/slug.
	 *
	 * @var string
	 */
	public const RESOURCE_SCHEMA_KEY = 'resource_schema';

	/**
	 * App-config key for the Service schema id/slug.
	 *
	 * @var string
	 */
	public const SERVICE_SCHEMA_KEY = 'service_schema';

	/**
	 * Delete the availability-cache entry for a resource on a date.
	 *
	 * Idempotent: a missing cache row is a no-op.
	 *
	 * @param string $resourceId Resource UUID/slug.
	 * @param string $date ISO date `YYYY-MM-DD`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function invalidateCache(string $resourceId, string $date): void;

	/**
	 * Delete the cached availability of every resource a service can run on:
	 * the resources whose `type` is one of the service's required resource
	 * types, or every resource when the service names none.
	 *
	 * @param array<int, string> $resourceTypes The service's `requiredResourceTypes`.
	 *
	 * @return int How many rows were deleted.
	 *
	 * @spec openspec/specs/appointment-booking/spec.md#requirement-the-server-expires-cached-availability-when-a-resource-or-service-changes
	 */
	public function invalidateForResourceTypes(array $resourceTypes): int;

	/**
	 * Delete every availability-cache row of a resource, whatever its date.
	 *
	 * Runs on the server for every writer of the resource (the UI, the API, an
	 * import): its hours, vacations or type changed, so no cached day is still
	 * true. A row that cannot be deleted is logged; the hourly refresh job
	 * converges it.
	 *
	 * @param string $resourceId Resource UUID.
	 *
	 * @return int How many rows were deleted.
	 *
	 * @spec openspec/specs/appointment-booking/spec.md#requirement-the-server-expires-cached-availability-when-a-resource-or-service-changes
	 */
	public function invalidateResource(string $resourceId): int;
}//end interface
