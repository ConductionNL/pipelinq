<?php

/**
 * Pipelinq SegmentProperty.
 *
 * A sendable property a segment rule leaf compares, and whether it is numeric.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/segment-groups-its-rules/design.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

/**
 * The property a compiled leaf filters on; used only inside SegmentQueryCompiler.
 *
 * @spec openspec/changes/segment-groups-its-rules/design.md
 */
final class SegmentProperty {
	/**
	 * Constructor.
	 *
	 * @param string $field   The property.
	 * @param bool   $numeric Whether the property is numeric.
	 */
	public function __construct(
		public readonly string $field,
		public readonly bool $numeric,
	) {
	}//end __construct()
}//end class
