<?php

/**
 * Pipelinq FieldMatch.
 *
 * One field-value pair a register lookup matches on (`productId`, `contactsUid`).
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
 * @spec openspec/specs/product-vendor-master/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * A field and the value it must have.
 *
 * @spec openspec/specs/product-vendor-master/spec.md
 */
final class FieldMatch {
	/**
	 * Constructor.
	 *
	 * @param string $field The property name to filter on.
	 * @param string $value The value to match.
	 */
	public function __construct(
		public readonly string $field,
		public readonly string $value,
	) {
	}//end __construct()
}//end class
