<?php

/**
 * Pipelinq DemoSeedTarget.
 *
 * Where one demo object is written: store, register, schema and ticket type.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Demo
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
 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Demo;

/**
 * The store, register, schema and (for the ticket sections) ticket type a
 * demo object is written to.
 *
 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
 */
class DemoSeedTarget {
	/**
	 * Constructor.
	 *
	 * @param object      $objectService The OpenRegister ObjectService.
	 * @param string      $registerId    The register to write into.
	 * @param string      $schemaId      The schema for this section.
	 * @param string|null $ticketType    The ticket discriminator, or null for a plain write.
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $registerId,
		public readonly string $schemaId,
		public readonly ?string $ticketType,
	) {
	}//end __construct()
}//end class
