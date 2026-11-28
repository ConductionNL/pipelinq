<?php

/**
 * Pipelinq FollowUpSweep.
 *
 * One reminder pass of {@see FollowUpReminderJob}: where it reads and the
 * window it reminds for.
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/email-calendar-sync/spec.md#requirement-follow-up-reminders-driven-by-the-calendar-leaf
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

/**
 * The object service, register and window of one reminder pass.
 *
 * @spec openspec/specs/email-calendar-sync/spec.md#requirement-follow-up-reminders-driven-by-the-calendar-leaf
 */
final class FollowUpSweep {

	/**
	 * Constructor.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $register      The register.
	 * @param int    $now           The current timestamp.
	 * @param int    $windowEnd     The timestamp the window ends at.
	 *
	 * @spec openspec/specs/email-calendar-sync/spec.md#requirement-follow-up-reminders-driven-by-the-calendar-leaf
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly int $now,
		public readonly int $windowEnd,
	) {
	}//end __construct()
}//end class
