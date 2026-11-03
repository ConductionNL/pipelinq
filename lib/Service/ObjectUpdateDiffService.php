<?php

/**
 * Pipelinq ObjectUpdateDiffService.
 *
 * Service for detecting field changes between old and new object data.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/crm-workflow-automation/spec.md#requirement-trigger-conditions
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * Service for detecting field changes between old and new object data.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @spec exclude infrastructure utility with no feature requirement of its own; it is
 *   exercised through the features that call it
 */
class ObjectUpdateDiffService {
	/**
	 * Lower-cased stage names that close a deal, and how.
	 *
	 * @var array<string, string>
	 */
	private const STAGE_OUTCOMES = [
		'won' => 'won',
		'gewonnen' => 'won',
		'closed won' => 'won',
		'lost' => 'lost',
		'verloren' => 'lost',
		'closed lost' => 'lost',
	];

	/**
	 * Check if the assignee has changed and dispatch if so.
	 *
	 * @param array<string, mixed> $change The update: new and old object data (`new`, `old`), and the
	 *                                     entity type, title, object id and current assignee.
	 * @param ObjectEventDispatcher $dispatcher The event dispatcher.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/crm-workflow-automation/spec.md#requirement-trigger-conditions
	 */
	public function dispatchAssigneeChangeIfNeeded(array $change, ObjectEventDispatcher $dispatcher): void {
		$assignee = $change['assignee'];
		if ($assignee === '' || $assignee === ($change['old']['assignee'] ?? '')) {
			return;
		}

		$dispatcher->dispatchAssigneeChange(
			entityType: $change['entityType'],
			title: $change['title'],
			objectId: $change['objectId'],
			assignee: $assignee
		);
	}//end dispatchAssigneeChangeIfNeeded()

	/**
	 * Check if the stage has changed and dispatch if so: a won or lost stage
	 * (by name, in English or Dutch) as a deal won or lost, any other as a
	 * stage change.
	 *
	 * @param array<string, mixed> $change The update: new and old object data (`new`, `old`), and the
	 *                                     entity type, title, object id and current assignee.
	 * @param ObjectEventDispatcher $dispatcher The event dispatcher.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/crm-workflow-automation/spec.md#requirement-trigger-conditions
	 */
	public function dispatchStageChangeIfNeeded(array $change, ObjectEventDispatcher $dispatcher): void {
		$newStage = $change['new']['stage'] ?? '';
		if ($newStage === '' || $newStage === ($change['old']['stage'] ?? '')) {
			return;
		}

		$outcome = (self::STAGE_OUTCOMES[strtolower($newStage)] ?? null);
		if ($outcome === 'won') {
			$value = (string)($change['new']['value'] ?? '0');
			$dispatcher->dispatchDealWon(title: $change['title'], value: $value, objectId: $change['objectId'], assignee: $change['assignee']);
			return;
		}

		if ($outcome === 'lost') {
			$dispatcher->dispatchDealLost(title: $change['title'], objectId: $change['objectId'], assignee: $change['assignee']);
			return;
		}

		$dispatcher->dispatchStageChange(title: $change['title'], objectId: $change['objectId'], newStage: $newStage, assignee: $change['assignee']);
	}//end dispatchStageChangeIfNeeded()

	/**
	 * Check if the lead value has genuinely changed.
	 *
	 * Both sides are cast to float before comparison to prevent a spurious
	 * `lead_value_changed` event when JSON deserialisation returns `100` (int)
	 * for a value that was stored as `100.0` (float).
	 *
	 * @param array $newData The new object data.
	 * @param array $oldData The old object data.
	 *
	 * @return bool True when the value changed by more than epsilon.
	 *
	 * @spec openspec/specs/crm-workflow-automation/spec.md#requirement-trigger-conditions
	 */
	public function hasValueChanged(array $newData, array $oldData): bool {
		$newValue = (float)($newData['value'] ?? 0);
		$oldValue = (float)($oldData['value'] ?? 0);

		// Use a small epsilon to avoid float rounding noise; CRM values are
		// currency amounts so 0.0001 is well below any meaningful delta.
		return abs($newValue - $oldValue) >= 0.0001;
	}//end hasValueChanged()

	/**
	 * Check if the status has changed and dispatch if so.
	 *
	 * @param array<string, mixed> $change The update: new and old object data (`new`, `old`), and the
	 *                                     entity type, title, object id and current assignee.
	 * @param ObjectEventDispatcher $dispatcher The event dispatcher.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/crm-workflow-automation/spec.md#requirement-trigger-conditions
	 */
	public function dispatchStatusChangeIfNeeded(array $change, ObjectEventDispatcher $dispatcher): void {
		$newStatus = $change['new']['status'] ?? '';
		if ($newStatus === '' || $newStatus === ($change['old']['status'] ?? '')) {
			return;
		}

		$dispatcher->dispatchStatusChange(title: $change['title'], objectId: $change['objectId'], newStatus: $newStatus, assignee: $change['assignee']);
	}//end dispatchStatusChangeIfNeeded()
}//end class
