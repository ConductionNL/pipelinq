<?php

/**
 * Pipelinq SitemapHop
 *
 * The source a sitemap is read through and how deep into an index the read is.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Competitor
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-sitemap-watch-reports-new-and-changed-locations
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Competitor;

/**
 * The source id and the depth of one sitemap read.
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-sitemap-watch-reports-new-and-changed-locations
 */
final class SitemapHop {

	/**
	 * Constructor.
	 *
	 * @param string $sourceId The source to read through.
	 * @param int    $depth    How deep into an index this read is.
	 */
	public function __construct(
		public readonly string $sourceId,
		public readonly int $depth,
	) {
	}//end __construct()

	/**
	 * The hop one level further into an index.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-sitemap-watch-reports-new-and-changed-locations
	 */
	public function deeper(): self {
		return new self(sourceId: $this->sourceId, depth: ($this->depth + 1));
	}//end deeper()
}//end class
