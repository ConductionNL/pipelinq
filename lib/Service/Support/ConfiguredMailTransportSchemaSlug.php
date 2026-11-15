<?php

/**
 * Pipelinq ConfiguredMailTransportSchemaSlug.
 *
 * The MailTransport schema slug from app config, with the host class's default.
 *
 * @category Util
 * @package  OCA\Pipelinq\Service\Support
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

namespace OCA\Pipelinq\Service\Support;

use OCA\Pipelinq\Kernel\AppIdentity;
use Throwable;

/**
 * Reads the 'mailTransport_schema' app-config value and finds one mail
 * transport in it. The host class provides IAppConfig `$appConfig` and
 * LoggerInterface `$logger` properties, a getRegisterSlug() step and a
 * DEFAULT_MAIL_TRANSPORT_SCHEMA_SLUG constant.
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

	/**
	 * Find one `mailTransport` by id as OpenRegister returns it; null when
	 * there is no ObjectService or the lookup throws (logged).
	 *
	 * @param object|null $objectService The OpenRegister ObjectService, or null when unavailable.
	 * @param string      $id            MailTransport UUID or slug.
	 *
	 * @return mixed The found object, or null.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function findMailTransport(?object $objectService, string $id): mixed {
		if ($objectService === null) {
			return null;
		}

		try {
			return $objectService->find(
				id: $id,
				register: $this->getRegisterSlug(),
				schema: $this->getMailTransportSchemaSlug(),
			);
		} catch (Throwable $e) {
			$this->logger->info(
				substr(strrchr(self::class, '\\'), 1).'.loadOne: not found',
				['id' => $id, 'exception' => $e->getMessage()]
			);
			return null;
		}
	}//end findMailTransport()
}//end trait
