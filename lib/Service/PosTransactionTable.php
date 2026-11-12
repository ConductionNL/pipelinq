<?php

/**
 * Pipelinq PosTransactionTable.
 *
 * The register and schema the POS transactions live in.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/archive/2026-06-14-pos-staff-pin-permissions/tasks.md#10.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * The configured register and schema ids of the posTransaction objects.
 *
 * @spec openspec/changes/archive/2026-06-14-pos-staff-pin-permissions/tasks.md#10.2
 */
final class PosTransactionTable {
	/**
	 * Constructor.
	 *
	 * @param string $register The register id.
	 * @param string $schema   The schema id.
	 */
	public function __construct(
		public readonly string $register,
		public readonly string $schema,
	) {
	}//end __construct()
}//end class
