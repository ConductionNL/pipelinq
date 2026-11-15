<?php

/**
 * Pipelinq JourneyCompileRun.
 *
 * One compilation of a journey into a flow by {@see JourneyService}.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Marketing
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
 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

/**
 * The journey being compiled, the id it is stored under and the identity its flow runs as.
 *
 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
 */
final class JourneyCompileRun {

	/**
	 * Constructor.
	 *
	 * @param string               $journeyId Journey UUID or slug.
	 * @param array<string, mixed> $journey   The journey.
	 * @param string               $runAs     The identity a scheduled journey runs as.
	 *
	 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
	 */
	public function __construct(
		public readonly string $journeyId,
		public readonly array $journey,
		public readonly string $runAs,
	) {
	}//end __construct()
}//end class
