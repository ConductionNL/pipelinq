<?php

/**
 * Pipelinq ConfiguredSchemaSlug.
 *
 * A slug from app config by key, with a fallback.
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
 * Reads an app-config value by key, answering the given default when it is
 * empty. The host class provides an IAppConfig `$appConfig` property.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait ConfiguredSchemaSlug {

	/**
	 * App-config schema slug override.
	 *
	 * @param string $key App-config key.
	 * @param string $default Built-in default.
	 *
	 * @return string Slug.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function resolveSchemaSlug(string $key, string $default): string {
		$slug = $this->appConfig->getValueString(AppIdentity::APP_ID, $key, '');
		if ($slug !== '') {
			return $slug;
		}

		return $default;
	}//end resolveSchemaSlug()
}//end trait
