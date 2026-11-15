<?php

/**
 * Contract of EligibilityService
 *
 * The public surface of EligibilityService that other components call, so they depend
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
 * Contract of EligibilityService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EligibilityServiceInterface {

	/**
	 * Return the Resources that MATCH a Service: the skill-eligible resources
	 * (the booking path's rule, REQ-APT-004) that are also active and bookable
	 * and of a resource type the Service asks for.
	 *
	 * The skill match is exactly {@see getEligibleResources()}; this adds the
	 * three filters REQ-SRG-002 names on top of it, so the list and the service
	 * page count the resources that could really be booked, not every skilled
	 * one. A Service that names no `requiredResourceTypes` accepts any type.
	 *
	 * @param string $serviceId Service UUID/slug. Empty returns an empty list.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/services-and-resources-at-a-glance/specs/appointment-booking/spec.md#requirement-each-service-shows-the-resources-that-match-it-req-srg-002
	 */
	public function getMatchingResources(string $serviceId): array;

	/**
	 * The matching-resource count per Service, for the services list. An empty
	 * `$serviceIds` counts every Service on the configured register/schema.
	 *
	 * @param array<int, string> $serviceIds The services to count, or [] for all.
	 *
	 * @return array<string, int> Service id to matching-resource count.
	 *
	 * @spec openspec/changes/services-and-resources-at-a-glance/specs/appointment-booking/spec.md#requirement-each-service-shows-the-resources-that-match-it-req-srg-002
	 */
	public function matchingResourceCounts(array $serviceIds = []): array;
}//end interface
