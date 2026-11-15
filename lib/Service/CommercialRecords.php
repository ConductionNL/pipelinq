<?php

/**
 * Pipelinq CommercialRecords.
 *
 * The lead and POS transaction rows a commercial overview reads.
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
 * @spec openspec/specs/commercial-dashboard/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * The lead rows and POS transaction rows behind the commercial figures.
 *
 * @spec openspec/specs/commercial-dashboard/spec.md
 */
final class CommercialRecords {
	/**
	 * Constructor.
	 *
	 * @param array<int, array<string, mixed>> $leads Lead rows.
	 * @param array<int, array<string, mixed>> $pos   POS transaction rows.
	 */
	public function __construct(
		public readonly array $leads,
		public readonly array $pos,
	) {
	}//end __construct()
}//end class
