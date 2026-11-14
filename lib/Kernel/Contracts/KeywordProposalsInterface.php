<?php

/**
 * Contract of KeywordProposals
 *
 * The public surface of KeywordProposals that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

/**
 * Contract of KeywordProposals.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface KeywordProposalsInterface {

	/**
	 * App-config key overriding the impression floor every derivation uses.
	 *
	 * @var string
	 */
	public const FLOOR_KEY = 'search.striking_min_impressions';

	/**
	 * Every derivation for a window.
	 *
	 * @param array{from: ?string, to: ?string, property: ?string} $window Window start and end (`YYYY-MM-DD`) and an optional property.
	 *
	 * @return array<string, mixed> `{from, to, minImpressions, buckets[], strikingDistance[], cannibalisation[], gaps[], crawl}`.
	 *
	 * @spec openspec/specs/marketing-keyword-intelligence/spec.md#requirement-queries-are-grouped-into-position-buckets
	 */
	public function forWindow(array $window): array;
}//end interface
