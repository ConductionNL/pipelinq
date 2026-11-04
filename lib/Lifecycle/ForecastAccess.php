<?php

/**
 * Pipelinq ForecastAccess.
 *
 * The acting user of a forecast request and the scope checks made for them,
 * each refusal logged.
 *
 * @category Lifecycle
 * @package  OCA\Pipelinq\Lifecycle
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
 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-011
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Lifecycle;

use OCA\Pipelinq\Service\ForecastSnapshotQuery;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Resolves the session user and asks {@see ForecastAccessPolicy} whether that
 * user may read or override at a scope. A refusal is logged as a warning.
 *
 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-011
 */
class ForecastAccess {
	/**
	 * Constructor.
	 *
	 * @param IUserSession         $userSession  The user session.
	 * @param ForecastAccessPolicy $accessPolicy The forecast access policy.
	 * @param LoggerInterface      $logger       The logger.
	 */
	public function __construct(
		private IUserSession $userSession,
		private ForecastAccessPolicy $accessPolicy,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The session user's id.
	 *
	 * @return string The user UID, or an empty string without a session.
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-011
	 */
	public function actingUserId(): string {
		$user = $this->userSession->getUser();
		if ($user instanceof IUser) {
			return $user->getUID();
		}

		return '';
	}//end actingUserId()

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
	public function mayRead(string $uid, ForecastSnapshotQuery $query): bool {
		$level = $query->level();
		$ownerId = $query->ownerId();
		if ($this->accessPolicy->canRead(userId: $uid, level: $level, ownerId: $ownerId) === true) {
			return true;
		}

		$this->logger->warning('Pipelinq: forecast read denied', ['uid' => $uid, 'level' => $level, 'ownerId' => $ownerId]);
		return false;
	}//end mayRead()

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
	public function mayOverride(string $uid, string $level): bool {
		if ($this->accessPolicy->canOverride(userId: $uid, level: $level) === true) {
			return true;
		}

		$this->logger->warning('Pipelinq: forecast override denied', ['uid' => $uid, 'level' => $level]);
		return false;
	}//end mayOverride()

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
	public function mayDeleteOverride(string $uid, array $override): bool {
		$level = (string)($override['level'] ?? '');
		if ($this->accessPolicy->canOverride(userId: $uid, level: $level) === true) {
			return true;
		}

		$this->logger->warning('Pipelinq: forecast override delete denied', ['uid' => $uid, 'id' => $override['requestedId']]);
		return false;
	}//end mayDeleteOverride()
}//end class
