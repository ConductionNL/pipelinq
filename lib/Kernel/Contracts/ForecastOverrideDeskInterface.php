<?php

/**
 * Contract of ForecastOverrideDesk
 *
 * The public surface of ForecastOverrideDesk that other components call, so they depend
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

use OCP\IRequest;

/**
 * Contract of ForecastOverrideDesk.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ForecastOverrideDeskInterface {

	/**
	 * Create an override for a manager.
	 *
	 * @param array<string, mixed> $payload   The validated payload.
	 * @param string               $managerId The acting manager.
	 *
	 * @return array<string, mixed>|null The created override, or null when it failed.
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-006-01
	 */
	public function create(array $payload, string $managerId): ?array;

	/**
	 * Delete a stored override.
	 *
	 * @param string $id The override UUID.
	 *
	 * @return bool Whether it was deleted.
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-006-06
	 */
	public function delete(string $id): bool;

	/**
	 * Why a payload cannot be stored.
	 *
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return string|null The error, or null when valid.
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-006-01
	 */
	public function invalidity(array $payload): ?string;

	/**
	 * The override payload of a create request.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-006-01
	 */
	public function payloadOf(IRequest $request): array;

	/**
	 * A stored override.
	 *
	 * @param string $id The override UUID.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-006-06
	 */
	public function stored(string $id): ?array;
}//end interface
