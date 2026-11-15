<?php

/**
 * Pipelinq ConfiguredWebhookToken.
 *
 * Checks a CTI webhook token against the configured one.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Cti\Adapter\Support
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
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Cti\Adapter\Support;

use OCA\Pipelinq\Kernel\AppIdentity;

/**
 * Compares a presented webhook token with the one in app config, in constant
 * time. The host class provides an IAppConfig `$appConfig` property.
 *
 * @spec exclude shared plumbing of the CTI adapters, no spec surface of its own.
 */
trait ConfiguredWebhookToken {

	/**
	 * Whether the presented token equals the configured one; never when either is empty.
	 *
	 * @param string $configKey The app-config key of the expected token.
	 * @param string $signature The presented token.
	 *
	 * @return bool
	 *
	 * @spec exclude shared plumbing of the CTI adapters, no spec surface of its own.
	 */
	private function matchesConfiguredToken(string $configKey, string $signature): bool {
		$expected = $this->appConfig->getValueString(AppIdentity::APP_ID, $configKey, '');
		if ($expected === '' || $signature === '') {
			return false;
		}

		return hash_equals($expected, $signature);
	}//end matchesConfiguredToken()
}//end trait
