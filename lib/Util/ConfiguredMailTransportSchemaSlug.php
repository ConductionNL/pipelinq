<?php

/**
 * Pipelinq ConfiguredMailTransportSchemaSlug.
 *
 * The MailTransport schema slug from app config, with the host class's default.
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
 * Reads the 'mailTransport_schema' app-config value. The host class provides
 * an IAppConfig `$appConfig` property and a DEFAULT_MAIL_TRANSPORT_SCHEMA_SLUG
 * constant.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait ConfiguredMailTransportSchemaSlug {

	/**
	 * The configured `mailTransport` schema slug.
	 *
	 * @return string
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function getMailTransportSchemaSlug(): string {
		$slug = $this->appConfig->getValueString(AppIdentity::APP_ID, 'mailTransport_schema', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_MAIL_TRANSPORT_SCHEMA_SLUG;
	}//end getMailTransportSchemaSlug()
}//end trait
