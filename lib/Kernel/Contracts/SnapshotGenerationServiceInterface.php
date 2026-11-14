<?php

/**
 * Contract of SnapshotGenerationService
 *
 * The public surface of SnapshotGenerationService that other components call, so they depend
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

use DateTimeImmutable;

/**
 * Contract of SnapshotGenerationService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SnapshotGenerationServiceInterface {

	/**
	 * App-config key for the comma-separated list of forecast team group ids.
	 *
	 * @var string
	 */
	public const TEAMS_KEY = 'forecast_team_groups';

	/**
	 * Generate and persist snapshots for the open period.
	 *
	 * @param DateTimeImmutable|null $asOf The snapshot date (defaults to today).
	 *
	 * @return array<string, mixed> A summary: period_id, counts and any errors.
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-004-01
	 */
	public function generate(?DateTimeImmutable $asOf = null): array;
}//end interface
