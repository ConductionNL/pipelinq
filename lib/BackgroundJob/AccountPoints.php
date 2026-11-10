<?php

/**
 * Pipelinq AccountPoints
 *
 * The points balance of one loyalty account.
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
 * An account id with its current balance.
 *
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-005
 */
final class AccountPoints {

	/**
	 * Constructor.
	 *
	 * @param string $accountId The account id.
	 * @param int    $balance   The current balance.
	 */
	public function __construct(
		public readonly string $accountId,
		public readonly int $balance,
	) {
	}//end __construct()
}//end class
