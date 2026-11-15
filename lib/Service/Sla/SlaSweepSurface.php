<?php

/**
 * Pipelinq SlaSweepSurface.
 *
 * One schema, narrowed by filters, that an SLA sweep walks.
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
 * A tracked surface within a sweep run: the schema, the tracked object type
 * the SLA engine escalates on, and the extra filters that narrow the schema.
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 */
class SlaSweepSurface {
	/**
	 * Constructor.
	 *
	 * @param SlaSweepRun          $run          The sweep run.
	 * @param string               $schemaId     Schema UUID.
	 * @param string               $type         Tracked object type label.
	 * @param array<string, mixed> $extraFilters Extra OpenRegister filters.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly SlaSweepRun $run,
		public readonly string $schemaId,
		public readonly string $type,
		public readonly array $extraFilters,
	) {
	}//end __construct()

	/**
	 * The OpenRegister filters that select this surface's objects.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function filters(): array {
		return array_merge(
			[
				'register' => $this->run->register,
				'schema'   => $this->schemaId,
			],
			$this->extraFilters
		);
	}//end filters()
}//end class
