<?php

/**
 * Pipelinq ShillinqApproval.
 *
 * Who approved an expense or time entry for Shillinq, and when.
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
 * @spec openspec/changes/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * The approver and approval time a Shillinq hand-off carries.
 *
 * @spec openspec/changes/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-003
 */
final class ShillinqApproval {
	/**
	 * Constructor.
	 *
	 * @param string $approvedBy The approving user id.
	 * @param string $approvedAt The ISO 8601 approval timestamp, or '' when unknown.
	 */
	public function __construct(
		public readonly string $approvedBy,
		public readonly string $approvedAt,
	) {
	}//end __construct()
}//end class
