<?php

/**
 * Pipelinq CallCompletion.
 *
 * How a CTI call ended, written on its contactmoment ticket.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Cti
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/cti-screenpop-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Cti;


/**
 * The talk duration and the disposition of a call that ended.
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-2.1
 */
final class CallCompletion {
	/**
	 * Constructor.
	 *
	 * @param int    $durationSeconds Talk duration.
	 * @param string $outcome         Disposition outcome (may be empty).
	 * @param string $subject         Disposition subject.
	 * @param string $notes           Disposition notes.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly int $durationSeconds,
		public readonly string $outcome,
		public readonly string $subject,
		public readonly string $notes,
	) {
	}//end __construct()

	/**
	 * The disposition outcome to record: the given one, or resolved when none was given.
	 *
	 * @return string The outcome.
	 *
	 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-2.1
	 */
	public function dispositionOutcome(): string {
		$dispositionOutcome = 'resolved';
		if ($this->outcome !== '') {
			$dispositionOutcome = $this->outcome;
		}

		return $dispositionOutcome;
	}//end dispositionOutcome()
}//end class
