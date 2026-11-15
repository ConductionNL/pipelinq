<?php

/**
 * Contract of ActivityTimelineService
 *
 * The public surface of ActivityTimelineService that other components call, so they depend
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
 * Contract of ActivityTimelineService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ActivityTimelineServiceInterface {

	/**
	 * Create a worklog entry as a contactmoment ticket with channel='worklog'.
	 *
	 * Written to the unified `ticket` schema with `ticketType=contactmoment`
	 * (unify-ticket-supertype) using the ticket field names: description
	 * (was summary), occurredAt (was contactedAt), assignee (was agent) and
	 * parentTicket (was request).
	 *
	 * The acting agent is derived from the current IUserSession and MUST NOT be
	 * supplied via request payload.
	 *
	 * @param string $entityType The entity type (client|request).
	 * @param string $entityId The entity UUID.
	 * @param array<string,mixed> $data The worklog data (duration, description, date).
	 *
	 * @return array<string,mixed> The normalised created worklog item.
	 *
	 * @throws \RuntimeException If configuration or services are missing.
	 *
	 * @spec openspec/specs/activity-timeline/spec.md#requirement-timeline-must-support-manual-entries
	 */
	public function createWorklog(string $entityType, string $entityId, array $data): array;

	/**
	 * Build the merged, sorted, paginated activity timeline for an entity.
	 *
	 * @param string $entityType The entity type (client|request|lead|contact).
	 * @param string $entityId The entity UUID.
	 * @param array<string,mixed> $params Request parameters: from, to, types[], _page, _limit.
	 *
	 * @return array{items: array<int,array<string,mixed>>, total: int, page: int, pages: int}
	 *
	 * @spec openspec/specs/activity-timeline/spec.md#requirement-every-entity-must-have-a-timeline-view
	 */
	public function getTimeline(string $entityType, string $entityId, array $params): array;

	/**
	 * Return paginated worklog entries (contactmomenten where channel='worklog')
	 * for an entity, including a summed `totalDuration` field.
	 *
	 * @param string $entityType The entity type (client|request).
	 * @param string $entityId The entity UUID.
	 * @param array<string,mixed> $params Request parameters: _page, _limit.
	 *
	 * @return array{items: array<int,array<string,mixed>>, total: int, page: int, pages: int, totalDuration: string}
	 *
	 * @spec openspec/specs/activity-timeline/spec.md#requirement-timeline-must-support-manual-entries
	 */
	public function getWorklog(string $entityType, string $entityId, array $params): array;
}//end interface
