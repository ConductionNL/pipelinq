<?php

/**
 * Pipelinq LeadBackfillPass.
 *
 * Where the backfilled leads are saved and as whom, the pipeline an
 * unassigned lead gets, the stored pipelines a lead is placed in and the
 * output warnings go to.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/lead-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use OCA\Pipelinq\Repair\Support\SchemaWriteTarget;
use OCP\Migration\IOutput;

/**
 * What one lead backfill run applies to every lead.
 *
 * @spec openspec/specs/lead-management/spec.md
 */
final class LeadBackfillPass {
	/**
	 * Constructor.
	 *
	 * @param SchemaWriteTarget              $target    Where to save the leads, and as whom.
	 * @param ?string                        $pipeline  The pipeline to assign, or null when none exists.
	 * @param array<int,array<string,mixed>> $pipelines Stored pipelines, to place a lead in a stage.
	 * @param IOutput                        $output    Output.
	 *
	 * @spec openspec/specs/lead-management/spec.md
	 */
	public function __construct(
		public readonly SchemaWriteTarget $target,
		public readonly ?string $pipeline,
		public readonly array $pipelines,
		public readonly IOutput $output,
	) {
	}//end __construct()
}//end class
