<?php

/**
 * Contract of ForecastAccessPolicy
 *
 * The public surface of ForecastAccessPolicy that other components call, so they depend
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
 * Contract of ForecastAccessPolicy.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-011
 */
interface ForecastAccessPolicyInterface {

	/**
	 * App-config key for the forecast manager group.
	 *
	 * @var string
	 */
	public const MANAGER_GROUP_KEY = 'forecast_manager_group';

	/**
	 * Whether a user is a forecast manager (member of the manager group or admin).
	 *
	 * @param string $userId The acting user UID.
	 *
	 * @return bool True when the user is a forecast manager.
	 * @spec exclude forecast has no owning requirement. pipeline-insights defers its config
	 *   scenario to admin-settings, which never grew one
	 */
	public function isManager(string $userId): bool;

	/**
	 * Whether a user may read forecast snapshots at a level for an owner.
	 *
	 * A rep may read only their own rep-level snapshot. Anything above rep level,
	 * or another owner's data, requires the manager role.
	 *
	 * @param string $userId The acting user UID.
	 * @param string $level The requested level.
	 * @param string|null $ownerId The requested owner filter (null = all).
	 *
	 * @return bool True when reading is permitted.
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-011-01, REQ-FRC-010-03
	 */
	public function canRead(string $userId, string $level, ?string $ownerId): bool;

	/**
	 * Whether a user may create a forecast override at a level.
	 *
	 * Only managers may override. Override is never a self-service rep action.
	 *
	 * @param string $userId The acting user UID.
	 * @param string $level The override level.
	 *
	 * @return bool True when overriding is permitted.
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-011-02
	 */
	public function canOverride(string $userId, string $level): bool;

	/**
	 * Whether a user may set a quota at a level.
	 *
	 * Only managers may set quotas.
	 *
	 * @param string $userId The acting user UID.
	 * @param string $level The quota level.
	 *
	 * @return bool True when setting the quota is permitted.
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-011-03
	 */
	public function canSetQuota(string $userId, string $level): bool;
}//end interface
