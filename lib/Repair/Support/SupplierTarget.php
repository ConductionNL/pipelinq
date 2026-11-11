<?php

/**
 * Pipelinq SupplierTarget.
 *
 * Where ingested vendors are written: OpenRegister's ObjectService and the
 * configured register and supplier schema.
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
 * @spec openspec/specs/product-vendor-master/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair\Support;

/**
 * The supplier store one vendor ingest writes to; used only inside
 * IngestProductVendorMaster.
 *
 * @spec openspec/specs/product-vendor-master/spec.md
 */
final class SupplierTarget {
	/**
	 * Constructor.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $registerId    The pipelinq register.
	 * @param string $schemaId      The supplier schema.
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $registerId,
		public readonly string $schemaId,
	) {
	}//end __construct()
}//end class
