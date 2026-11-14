<?php

/**
 * Contract of JourneyStepRunner
 *
 * The public surface of JourneyStepRunner that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

/**
 * Contract of JourneyStepRunner.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface JourneyStepRunnerInterface {

	/**
	 * Run one journey's action for whatever the flow delivered.
	 *
	 * @param string $journeyId The journey.
	 * @param array<string, mixed> $subject The triggering object, empty for a scheduled journey.
	 * @param string $flowRunUuid The flow run, recorded on each outcome.
	 *
	 * @return array<int, array{contactId: string, state: string, reason: string}> One entry per contact.
	 *
	 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-refuses-a-send-without-consent-and-names-the-contact
	 */
	public function run(string $journeyId, array $subject = [], string $flowRunUuid = ''): array;
}//end interface
