<?php

/**
 * Contract of ForecastDealService
 *
 * The public surface of ForecastDealService that other components call, so they depend
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
 * Contract of ForecastDealService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ForecastDealServiceInterface {

	/**
	 * Fallback default forecast category assigned to a new deal. The canonical
	 * source of truth is `x-pipelinq-forecast-lifecycle.default`.
	 *
	 * @var string
	 */
	public const DEFAULT_CATEGORY = 'pipeline';

	/**
	 * App-config key for the commit justification threshold (in reporting currency).
	 *
	 * @var string
	 */
	public const COMMIT_THRESHOLD_KEY = 'forecast_commit_threshold';

	/**
	 * Default commit justification threshold when none is configured.
	 *
	 * @var int
	 */
	public const COMMIT_THRESHOLD_DEFAULT = 50000;

	/**
	 * Compute the default-assigned data for a freshly created deal.
	 *
	 * Idempotent: returns null when no change is required (category already set),
	 * otherwise the deal data with forecast_category defaulted to pipeline.
	 *
	 * @param array<string, mixed> $data The created deal data.
	 *
	 * @return array<string, mixed>|null The mutated data to persist, or null when unchanged.
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-001-01
	 */
	public function applyDefaultCategory(array $data): ?array;

	/**
	 * Compute the reset data when a closed deal is reopened.
	 *
	 * When the deal's status moves away from a closed value while the forecast
	 * category is still closed, the category resets to pipeline.
	 *
	 * @param array<string, mixed> $oldData The deal data before the update.
	 * @param array<string, mixed> $newData The deal data after the update.
	 *
	 * @return array<string, mixed>|null The mutated data to persist, or null when no reset applies.
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-002-03
	 */
	public function applyReopenReset(array $oldData, array $newData): ?array;

	/**
	 * Validate a forecast-category transition on an updated deal.
	 *
	 * Returns an error key when the transition is rejected, or null when allowed.
	 * Rules:
	 *  - A closed deal cannot move to an open category (must be reopened first).
	 *  - A commit on a deal above the threshold requires a justification of at
	 *    least {@see self::JUSTIFICATION_MIN_LENGTH} characters.
	 *
	 * @param array<string, mixed> $oldData The deal data before the update.
	 * @param array<string, mixed> $newData The deal data after the update.
	 *
	 * @return string|null The error key (translatable) or null when the change is valid.
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-002-01, REQ-FRC-003-01
	 */
	public function validateTransition(array $oldData, array $newData): ?string;
}//end interface
