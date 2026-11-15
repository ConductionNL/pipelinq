<?php

/**
 * Pipelinq ConfiguredRegisterSlug.
 *
 * The register slug from app config, with the host class's default.
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

/**
 * Reads the 'register' app-config value. The host class provides an
 * IAppConfig `$appConfig` property and a DEFAULT_REGISTER_SLUG constant.
 *
 * @spec exclude configuration plumbing shared by register readers, no behavioural spec surface.
 */
trait ConfiguredRegisterSlug {

	/**
	 * Resolve the register slug from app config.
	 *
	 * @return string Slug.
	 *
	 * @spec exclude configuration plumbing shared by register readers, no behavioural spec surface.
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_REGISTER_SLUG;
	}//end getRegisterSlug()
}//end trait
