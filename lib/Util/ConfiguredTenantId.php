<?php

/**
 * Pipelinq ConfiguredTenantId.
 *
 * The tenant id from app config, 'default' when unset.
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
 * Reads the 'tenant_id' app-config value. The host class provides an
 * IAppConfig `$appConfig` property.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait ConfiguredTenantId {

	/**
	 * Tenant id (app-config; defaults to `default`).
	 *
	 * @return string Tenant id.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function getTenantId(): string {
		$tenantId = $this->appConfig->getValueString(AppIdentity::APP_ID, 'tenant_id', '');
		if ($tenantId !== '') {
			return $tenantId;
		}

		return 'default';
	}//end getTenantId()
}//end trait
