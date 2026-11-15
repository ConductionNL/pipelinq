<?php

/**
 * Pipelinq PosContactSearch.
 *
 * One till-side contact search of {@see PosCustomerLinkService}.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/pos-customer-link/spec.md#REQ-PCL-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * The lower-cased query, the fields it is matched on and the result cap.
 *
 * @spec openspec/specs/pos-customer-link/spec.md#REQ-PCL-001
 */
final class PosContactSearch {

	/**
	 * Constructor.
	 *
	 * @param string             $query  The lower-cased query (already trimmed).
	 * @param array<int, string> $fields The enabled search fields.
	 * @param int                $cap    Max results.
	 *
	 * @spec openspec/specs/pos-customer-link/spec.md#REQ-PCL-001
	 */
	public function __construct(
		public readonly string $query,
		public readonly array $fields,
		public readonly int $cap,
	) {
	}//end __construct()
}//end class
