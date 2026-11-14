<?php

/**
 * Pipelinq MergesBreachEvents.
 *
 * Adds fired breach-event IDs to every SLA target.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Sla
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
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Sla;

/**
 * Each target keeps its own breach-event IDs plus the newly fired ones,
 * without duplicates.
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 */
trait MergesBreachEvents {
	/**
	 * The targets with the fired event IDs added to their breachEventIds.
	 *
	 * @param array<int|string, array<string, mixed>> $targets  The SLA targets.
	 * @param array<int, string>                      $eventIds The fired breach-event IDs.
	 *
	 * @return array<int|string, array<string, mixed>>
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	private function withBreachEvents(array $targets, array $eventIds): array {
		foreach ($targets as $idx => $target) {
			$known = (array) ($target['breachEventIds'] ?? []);
			$targets[$idx]['breachEventIds'] = array_values(array_unique(array_merge($known, $eventIds)));
		}

		return $targets;
	}//end withBreachEvents()
}//end trait
