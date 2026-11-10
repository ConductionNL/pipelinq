<?php

/**
 * Pipelinq TierChange.
 *
 * One tier re-evaluation of a CustomerLoyaltyAccount: the account id and the
 * account payload it was read from, with the programme, lifetime points and
 * current tier read off that payload.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * A tier re-evaluation of one loyalty account; built and read by TierService.
 *
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-003
 */
final class TierChange {
	/**
	 * Constructor.
	 *
	 * @param string               $accountId The account UUID.
	 * @param array<string, mixed> $account   The account payload.
	 */
	public function __construct(
		public readonly string $accountId,
		public readonly array $account,
	) {
	}//end __construct()

	/**
	 * The programme the account belongs to.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-003
	 */
	public function programmeId(): string {
		return (string)($this->account['programmeId'] ?? '');
	}//end programmeId()

	/**
	 * The account's lifetime points.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-003
	 */
	public function lifetimePoints(): int {
		return (int)($this->account['lifetimePoints'] ?? 0);
	}//end lifetimePoints()

	/**
	 * The account's current tier id as stored (null when it has none).
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-003
	 */
	public function currentTierId(): mixed {
		return $this->account['currentTierId'] ?? null;
	}//end currentTierId()
}//end class
