<?php

/**
 * Contract of QuotaService
 *
 * The public surface of QuotaService that other components call, so they depend
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
 * Contract of QuotaService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface QuotaServiceInterface {

	/**
	 * App-config key for the at-risk quota-attainment threshold (percent).
	 *
	 * @var string
	 */
	public const AT_RISK_PERCENT_KEY = 'forecast_at_risk_percent';

	/**
	 * App-config key for the at-risk days-remaining threshold.
	 *
	 * @var string
	 */
	public const AT_RISK_DAYS_KEY = 'forecast_at_risk_days';

	/**
	 * Default at-risk attainment threshold (90%).
	 *
	 * @var int
	 */
	public const AT_RISK_PERCENT_DEFAULT = 90;

	/**
	 * Default at-risk days-remaining threshold (30 days).
	 *
	 * @var int
	 */
	public const AT_RISK_DAYS_DEFAULT = 30;
}//end interface
