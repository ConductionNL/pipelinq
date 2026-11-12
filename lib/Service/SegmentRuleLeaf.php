<?php

/**
 * Pipelinq SegmentRuleLeaf.
 *
 * One leaf predicate of a segment rule tree whose field resolved to a
 * declared type.
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
 * @spec openspec/specs/marketing-segmentation/spec.md#requirement-segment-builder-composes-rule-trees
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * A leaf being validated: the node, its path, its field and the field's
 * declared type; used only inside SegmentService's rule-tree validation.
 *
 * @spec openspec/specs/marketing-segmentation/spec.md#requirement-segment-builder-composes-rule-trees
 */
final class SegmentRuleLeaf {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $node      The leaf node as written.
	 * @param string               $path      JSON-pointer-ish breadcrumb.
	 * @param string               $field     The leaf's field.
	 * @param string               $fieldType The field's declared JSON-schema type.
	 */
	public function __construct(
		public readonly array $node,
		public readonly string $path,
		public readonly string $field,
		public readonly string $fieldType,
	) {
	}//end __construct()
}//end class
