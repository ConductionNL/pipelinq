<?php

/**
 * Pipelinq PosBookkeepingStore.
 *
 * Where the POS bookkeeping migration reads and writes: OpenRegister's
 * ObjectService, the register, and the Z-reports it resolves outbounds against.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec exclude internal value of the MigratePosBookkeepingToShillinq repair step
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair\Support;

/**
 * The register one POS bookkeeping migration run works in; used only inside
 * MigratePosBookkeepingToShillinq.
 *
 * @spec exclude internal value of the MigratePosBookkeepingToShillinq repair step
 */
final class PosBookkeepingStore {
	/**
	 * Constructor.
	 *
	 * @param object                              $objectService OpenRegister's ObjectService.
	 * @param string                              $register      The register id/slug.
	 * @param array<string, array<string, mixed>> $zReportsById  The Z-reports keyed by id and slug.
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly array $zReportsById = [],
	) {
	}//end __construct()

	/**
	 * The same store with the Z-reports to resolve outbounds against.
	 *
	 * @param array<string, array<string, mixed>> $zReportsById The Z-reports keyed by id and slug.
	 *
	 * @return self The store.
	 *
	 * @spec exclude internal value of the MigratePosBookkeepingToShillinq repair step
	 */
	public function withZReports(array $zReportsById): self {
		return new self(objectService: $this->objectService, register: $this->register, zReportsById: $zReportsById);
	}//end withZReports()
}//end class
