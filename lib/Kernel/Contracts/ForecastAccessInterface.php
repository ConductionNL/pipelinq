<?php

/**
 * Contract of ForecastAccess
 *
 * The public surface of ForecastAccess that other components call, so they depend
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

use OCA\Pipelinq\Service\ForecastSnapshotQuery;

/**
 * Contract of ForecastAccess.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-011
 */
interface ForecastAccessInterface {

	/**
	 * The session user's id.
	 *
	 * @return string The user UID, or an empty string without a session.
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-011
	 */
	public function actingUserId(): string;

	/**
	 * Whether the user may read the snapshots a query asks for.
	 *
	 * @param string                $uid   The acting user.
	 * @param ForecastSnapshotQuery $query The snapshot query.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-011
	 */
	public function mayRead(string $uid, ForecastSnapshotQuery $query): bool;

	/**
	 * Whether the user may create an override at a level.
	 *
	 * @param string $uid   The acting user.
	 * @param string $level The hierarchy level.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-011
	 */
	public function mayOverride(string $uid, string $level): bool;

	/**
	 * Whether the user may delete a stored override, judged at its stored level.
	 *
	 * @param string               $uid      The acting user.
	 * @param array<string, mixed> $override The stored override, with the requested id under `requestedId`.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-006-06
	 */
	public function mayDeleteOverride(string $uid, array $override): bool;
}//end interface
