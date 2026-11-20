<?php

/**
 * Pipelinq RejectedTokenCount.
 *
 * Counts a rejected public token with the brute-force throttler.
 *
 * @category Util
 * @package  OCA\Pipelinq\Controller\Support
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller\Support;

use Throwable;

/**
 * Counts a rejected token against the host's THROTTLE_ACTION; a throttler
 * failure is logged under the host's short class name and swallowed. The host
 * class provides IThrottler `$throttler`, IRequest `$request` and
 * LoggerInterface `$logger` properties.
 *
 * The half that COUNTS; `#[BruteForceProtection]` is the half that
 * ENFORCES. Either alone is inert — see ADR-082.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait RejectedTokenCount {

	/**
	 * Count a rejected token with the brute-force throttler.
	 *
	 * @return void
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function registerRejectedToken(): void {
		try {
			$this->throttler->registerAttempt(
				action: self::THROTTLE_ACTION,
				ip: $this->request->getRemoteAddress()
			);
		} catch (Throwable $throttlerFailure) {
			$this->logger->warning(
				substr(strrchr(self::class, '\\'), 1) . ': registerAttempt failed: ' . $throttlerFailure->getMessage()
			);
		}
	}//end registerRejectedToken()
}//end trait
