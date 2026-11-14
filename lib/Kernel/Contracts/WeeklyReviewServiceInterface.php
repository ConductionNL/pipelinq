<?php

/**
 * Contract of WeeklyReviewService
 *
 * The public surface of WeeklyReviewService that other components call, so they depend
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
 * Contract of WeeklyReviewService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WeeklyReviewServiceInterface {

	/**
	 * Compose the review for the week containing a given day.
	 *
	 * @param string $weekStarting The Monday, `YYYY-MM-DD`. Empty means last week.
	 *
	 * @return array<string, mixed> The review, not yet stored.
	 *
	 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-four-sources-and-names-the-ones-with-nothing-in-them
	 */
	public function compose(string $weekStarting = ''): array;

	/**
	 * Compose the review and store it, one object per week.
	 *
	 * @param string $weekStarting The Monday, `YYYY-MM-DD`. Empty means last week.
	 *
	 * @return array<string, mixed>|null The stored review, or null when the write failed.
	 *
	 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-four-sources-and-names-the-ones-with-nothing-in-them
	 */
	public function generate(string $weekStarting = ''): ?array;

	/**
	 * The most recent stored review.
	 *
	 * @return array<string, mixed>|null The review, or null when none exists.
	 *
	 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-four-sources-and-names-the-ones-with-nothing-in-them
	 */
	public function latest(): ?array;
}//end interface
