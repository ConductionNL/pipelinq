<?php

/**
 * Pipelinq ConfiguredBlastDeliverySchemaSlug.
 *
 * The BlastDelivery schema slug from app config, with the host class's default.
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
 * Reads the 'blastDelivery_schema' app-config value. The host class provides
 * an IAppConfig `$appConfig` property and a DEFAULT_BLAST_DELIVERY_SCHEMA_SLUG
 * constant.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait ConfiguredBlastDeliverySchemaSlug {

	/**
	 * Resolve the BlastDelivery schema slug from app config.
	 *
	 * @return string Slug.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function getBlastDeliverySchemaSlug(): string {
		$slug = $this->appConfig->getValueString(AppIdentity::APP_ID, 'blastDelivery_schema', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_BLAST_DELIVERY_SCHEMA_SLUG;
	}//end getBlastDeliverySchemaSlug()
}//end trait
