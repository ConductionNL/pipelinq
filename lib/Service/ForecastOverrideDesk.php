<?php

/**
 * Pipelinq ForecastOverrideDesk.
 *
 * Handles the override requests of the forecast API on top of
 * ForecastOverrideService, logging a failed create.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
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
 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-006
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads an override payload from the request, and creates, finds and deletes
 * overrides. A create that fails is logged with its message and answered as
 * null, so no detail reaches the client.
 *
 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-006
 */
class ForecastOverrideDesk {
	/**
	 * Constructor.
	 *
	 * @param ForecastOverrideService $overrideService The override management service.
	 * @param LoggerInterface         $logger          The logger.
	 */
	public function __construct(
		private ForecastOverrideService $overrideService,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The override payload of a create request.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-006-01
	 */
	public function payloadOf(IRequest $request): array {
		return [
			'period_id' => (string)$request->getParam('period_id', ''),
			'override_owner_id' => (string)$request->getParam('override_owner_id', ''),
			'level' => (string)$request->getParam('level', ''),
			'category' => (string)$request->getParam('category', 'commit'),
			'override_amount' => $request->getParam('override_amount'),
			'reason' => (string)$request->getParam('reason', ''),
		];
	}//end payloadOf()

	/**
	 * Why a payload cannot be stored.
	 *
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return string|null The error, or null when valid.
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-006-01
	 */
	public function invalidity(array $payload): ?string {
		return $this->overrideService->validatePayload($payload);
	}//end invalidity()

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
	public function create(array $payload, string $managerId): ?array {
		try {
			return $this->overrideService->createOverride(payload: $payload, managerId: $managerId);
		} catch (Throwable $e) {
			$this->logger->error('Pipelinq: failed to create forecast override', ['exception' => $e->getMessage()]);
			return null;
		}
	}//end create()

	/**
	 * A stored override.
	 *
	 * @param string $id The override UUID.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-006-06
	 */
	public function stored(string $id): ?array {
		return $this->overrideService->getOverride($id);
	}//end stored()

	/**
	 * Delete a stored override.
	 *
	 * @param string $id The override UUID.
	 *
	 * @return bool Whether it was deleted.
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-006-06
	 */
	public function delete(string $id): bool {
		return $this->overrideService->deleteOverride($id);
	}//end delete()
}//end class
