<?php

/**
 * Contract of ForecastService
 *
 * The public surface of ForecastService that other components call, so they depend
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
 * Contract of ForecastService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ForecastServiceInterface {

	/**
	 * App-config key for the open-pipeline target the dashboard gauge
	 * measures against, in the reporting currency. 0 means no target.
	 *
	 * @var string
	 */
	public const PIPELINE_TARGET_KEY = 'pipeline_target';

	/**
	 * App-config key for the accuracy green band threshold.
	 *
	 * @var string
	 */
	public const ACCURACY_GREEN_KEY = 'forecast_accuracy_green';

	/**
	 * App-config key for the accuracy amber band threshold.
	 *
	 * @var string
	 */
	public const ACCURACY_AMBER_KEY = 'forecast_accuracy_amber';

	/**
	 * Default accuracy green band threshold (0.90).
	 *
	 * @var float
	 */
	public const ACCURACY_GREEN_DEFAULT = 0.90;

	/**
	 * Default accuracy amber band threshold (0.75).
	 *
	 * @var float
	 */
	public const ACCURACY_AMBER_DEFAULT = 0.75;
}//end interface
