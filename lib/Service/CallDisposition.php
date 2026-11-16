<?php

/**
 * Pipelinq CallDisposition.
 *
 * The outcome an agent records when wrapping up a call.
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
 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-2.4
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * The disposition of one contactmoment: subject, outcome, notes and, for an
 * escalation, the group the follow-up task goes to.
 *
 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-2.4
 */
final class CallDisposition {
	/**
	 * Constructor.
	 *
	 * @param string      $interactionId The contactmoment UUID.
	 * @param string      $subject       Disposition subject.
	 * @param string      $outcome       Outcome enum value.
	 * @param string      $notes         Free-text notes.
	 * @param string|null $groupId       The escalation group, or null.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $interactionId,
		public readonly string $subject,
		public readonly string $outcome,
		public readonly string $notes,
		public readonly ?string $groupId,
	) {
	}//end __construct()
}//end class
