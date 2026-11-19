<?php

/**
 * Contract of BookingFiguresService
 *
 * The public surface of BookingFiguresService that other components call, so they depend
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
 * Contract of BookingFiguresService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BookingFiguresServiceInterface {

	/**
	 * A service's bookings starting this week and its no-shows this month.
	 *
	 * @param string $serviceId The service id.
	 *
	 * @return array{bookingsThisWeek: int, noShowsThisMonth: int}
	 *
	 * @spec openspec/changes/services-and-resources-at-a-glance/specs/appointment-booking/spec.md#requirement-a-service-page-shows-how-busy-the-service-is-req-srg-003
	 */
	public function serviceFigures(string $serviceId): array;

	/**
	 * A resource's bookings this week and the share of its bookable time they fill.
	 *
	 * Bookable time is the resource's working hours on each day of this week,
	 * skipping the days a vacation covers. The share is a whole percentage of
	 * booked over bookable minutes, and null when there is no bookable time, so
	 * a resource on vacation all week shows its count without a share.
	 *
	 * @param string $resourceId The resource id.
	 *
	 * @return array{bookingsThisWeek: int, bookedMinutes: int, bookableMinutes: int, bookedShare: int|null}
	 *
	 * @spec openspec/changes/services-and-resources-at-a-glance/specs/appointment-booking/spec.md#requirement-a-resource-page-shows-how-much-of-its-bookable-time-is-booked-req-srg-004
	 */
	public function resourceFigures(string $resourceId): array;
}//end interface
