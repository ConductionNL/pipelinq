<?php

/**
 * Pipelinq SegmentRulePosition.
 *
 * Where in a segment rule tree validation is, and the schema properties it
 * validates against.
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
 * A node's breadcrumb path and the schema properties map; used only inside
 * SegmentService's rule-tree validation.
 *
 * @spec openspec/specs/marketing-segmentation/spec.md#requirement-segment-builder-composes-rule-trees
 */
final class SegmentRulePosition {
	/**
	 * Constructor.
	 *
	 * @param string               $path       JSON-pointer-ish breadcrumb, e.g. `$.children[0]`.
	 * @param array<string, mixed> $properties Schema properties map.
	 */
	public function __construct(
		public readonly string $path,
		public readonly array $properties,
	) {
	}//end __construct()

	/**
	 * The position of a child node, against the same schema.
	 *
	 * @param string $segment The path segment to append, e.g. `.children[1]`.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/marketing-segmentation/spec.md#requirement-segment-builder-composes-rule-trees
	 */
	public function child(string $segment): self {
		return new self(path: $this->path . $segment, properties: $this->properties);
	}//end child()
}//end class
