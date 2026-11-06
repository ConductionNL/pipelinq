<?php

/**
 * Pipelinq register access sync job
 *
 * Applies the CRM group and the CRM read group to pipelinq's registers when
 * a setting changed without passing the settings screen: an example set
 * writes app config directly, and so does `occ config:app:set`. While the
 * settings match what was applied last, a run reads nothing.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/close-pipelinq-register-to-staff/specs/register-access/spec.md#requirement-a-configured-read-group-reads-the-parties-and-contact-moments-and-writes-nothing
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\Access\RegisterAccessSync;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Apply a changed CRM group or CRM read group to the registers.
 *
 * @spec openspec/changes/close-pipelinq-register-to-staff/specs/register-access/spec.md#requirement-a-configured-read-group-reads-the-parties-and-contact-moments-and-writes-nothing
 */
class SyncRegisterAccessJob extends TimedJob {

	/**
	 * Run every five minutes; a run with nothing changed reads nothing.
	 */
	private const INTERVAL = 300;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory       $time   The time factory.
	 * @param RegisterAccessSync $access The sync.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly RegisterAccessSync $access,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL);
	}//end __construct()

	/**
	 * Apply the settings when they differ from what was applied.
	 *
	 * @param mixed $argument The job argument (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Required by TimedJob::run.
	 *
	 * @spec openspec/changes/close-pipelinq-register-to-staff/specs/register-access/spec.md#requirement-a-configured-read-group-reads-the-parties-and-contact-moments-and-writes-nothing
	 */
	protected function run(mixed $argument): void {
		$this->access->sync();
	}//end run()
}//end class
