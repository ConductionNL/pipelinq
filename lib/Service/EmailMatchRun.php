<?php

/**
 * Pipelinq EmailMatchRun
 *
 * One user's matching run over one owned mail account.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
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

namespace OCA\Pipelinq\Service;

/**
 * The user and the mail account their run scans.
 *
 * @spec openspec/specs/email-calendar-sync/spec.md
 */
final class EmailMatchRun {

	/**
	 * Constructor.
	 *
	 * @param string $userId    The user the run is for.
	 * @param int    $accountId The mail account the user owns.
	 */
	public function __construct(
		public readonly string $userId,
		public readonly int $accountId,
	) {
	}//end __construct()
}//end class
