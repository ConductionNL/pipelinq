<?php

/**
 * Pipelinq QueryContest.
 *
 * One query two or more of our pages compete for: the query's summed clicks
 * and impressions, and the pages that carry a real share of them.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Search
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
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-cannibalisation-names-two-pages-competing-for-one-query
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Search;

/**
 * Pages competing for one query.
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-cannibalisation-names-two-pages-competing-for-one-query
 */
final class QueryContest {
	/**
	 * Constructor.
	 *
	 * @param array{clicks: int, impressions: int}  $totals     The query's totals over all its pages.
	 * @param array<string, array<string, mixed>>   $contenders The pages carrying the minimum share.
	 */
	public function __construct(
		public readonly array $totals,
		public readonly array $contenders,
	) {
	}//end __construct()
}//end class
