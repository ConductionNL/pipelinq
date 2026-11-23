<?php

/**
 * Pipelinq BlastDeliveryCollection.
 *
 * Where a contact's BlastDelivery rows live in OpenRegister.
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
 * @spec openspec/specs/marketing-compliance/spec.md#requirement-consent-withdrawal-propagates
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * The ObjectService, register and schema BlastDelivery rows are read from and written to.
 *
 * @spec openspec/specs/marketing-compliance/spec.md#requirement-consent-withdrawal-propagates
 */
final class BlastDeliveryCollection {
	/**
	 * Constructor.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $register      Register slug.
	 * @param string $schema        BlastDelivery schema slug.
	 *
	 * @spec openspec/specs/marketing-compliance/spec.md#requirement-consent-withdrawal-propagates
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $schema,
	) {
	}//end __construct()
}//end class
