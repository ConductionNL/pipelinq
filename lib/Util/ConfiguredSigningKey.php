<?php

/**
 * Pipelinq ConfiguredSigningKey.
 *
 * The HMAC signing key from app config, generated and stored on first use.
 *
 * @category Util
 * @package  OCA\Pipelinq\Util
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

namespace OCA\Pipelinq\Util;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCP\Security\ISecureRandom;

/**
 * Reads the key under the host's SECRET_CONFIG_KEY and, when it is empty,
 * generates a 64-character key and stores it as a sensitive value. The host
 * class provides IAppConfig `$appConfig` and ISecureRandom `$secureRandom`
 * properties.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait ConfiguredSigningKey {

	/**
	 * Resolve (or lazily mint) the per-instance HMAC signing key.
	 *
	 * @return string The signing key.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function signingKey(): string {
		$key = $this->appConfig->getValueString(AppIdentity::APP_ID, self::SECRET_CONFIG_KEY, '');
		if ($key === '') {
			$key = $this->secureRandom->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
			$this->appConfig->setValueString(AppIdentity::APP_ID, self::SECRET_CONFIG_KEY, $key, false, true);
		}

		return $key;
	}//end signingKey()
}//end trait
