<?php

/**
 * Pipelinq ExpenseApprovedEvent.
 *
 * Domain event emitted when a pipelinq expense transitions to status=approved.
 * Carries the approved expense payload, the approver and the approval
 * timestamp; consumers map it to the Shillinq AP CloudEvents envelope.
 *
 * @category Event
 * @package  OCA\Pipelinq\Event
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Event;

use OCP\EventDispatcher\Event;

/**
 * Dispatched when an expense reaches status=approved.
 *
 * Constructed by ExpenseApprovalListener once it detects an approval
 * transition; carries the data needed by ShillinqApService to build the AP
 * CloudEvents payload without re-fetching the expense from OpenRegister.
 *
 * @spec openspec/changes/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-002
 */
class ExpenseApprovedEvent extends Event {
	/**
	 * Constructor.
	 *
	 * @param string $expenseUuid The expense UUID.
	 * @param array<string, mixed> $expense The expense payload.
	 * @param string $approvedBy The approving user id.
	 * @param string $approvedAt The ISO 8601 approval timestamp.
	 */
	public function __construct(
		private string $expenseUuid,
		private array $expense,
		private string $approvedBy,
		private string $approvedAt,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Get the expense UUID.
	 *
	 * @return string The expense UUID.
	 * @spec openspec/specs/time-approval-workflow/spec.md#requirement-approved-time-entries-are-emitted-to-shillinqs-time-intake
	 */
	public function getExpenseUuid(): string {
		return $this->expenseUuid;
	}//end getExpenseUuid()

	/**
	 * Get the expense payload.
	 *
	 * @return array<string, mixed> The expense data.
	 * @spec openspec/specs/time-approval-workflow/spec.md#requirement-approved-time-entries-are-emitted-to-shillinqs-time-intake
	 */
	public function getExpense(): array {
		return $this->expense;
	}//end getExpense()

	/**
	 * Get the approver's user id.
	 *
	 * @return string The user id.
	 * @spec openspec/specs/time-approval-workflow/spec.md#requirement-approved-time-entries-are-emitted-to-shillinqs-time-intake
	 */
	public function getApprovedBy(): string {
		return $this->approvedBy;
	}//end getApprovedBy()

	/**
	 * Get the ISO 8601 approval timestamp.
	 *
	 * @return string The timestamp.
	 * @spec openspec/specs/time-approval-workflow/spec.md#requirement-approved-time-entries-are-emitted-to-shillinqs-time-intake
	 */
	public function getApprovedAt(): string {
		return $this->approvedAt;
	}//end getApprovedAt()
}//end class
