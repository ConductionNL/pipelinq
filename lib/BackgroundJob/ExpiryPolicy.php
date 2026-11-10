<?php

/**
 * Pipelinq ExpiryPolicy
 *
 * A programme's inactivity expiry policy.
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

/**
 * How many inactive months expire a balance, and how many days' notice go before it.
 *
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-005
 */
final class ExpiryPolicy {

	/**
	 * Constructor.
	 *
	 * @param int $months Inactive months before the balance expires.
	 * @param int $notice Advance notice in days.
	 */
	public function __construct(
		public readonly int $months,
		public readonly int $notice,
	) {
	}//end __construct()

	/**
	 * Reads the policy of one programme.
	 *
	 * @param array<string, mixed> $programme The programme.
	 *
	 * @return self|null Null when the programme does not expire on inactivity.
	 *
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-005
	 */
	public static function fromProgramme(array $programme): ?self {
		$policy = $programme['expiryPolicy'] ?? [];
		if (is_array($policy) === false) {
			return null;
		}

		$type = (string)($policy['type'] ?? 'none');
		$months = (int)($policy['value'] ?? 0);
		$notice = (int)($policy['advanceNoticeDays'] ?? 30);
		if ($type !== 'inactivityMonths' || $months <= 0) {
			return null;
		}

		return new self(months: $months, notice: $notice);
	}//end fromProgramme()
}//end class
