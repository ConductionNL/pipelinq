<?php

/**
 * Pipelinq ChannelAdapterLookups.
 *
 * Lookups shared by the SMS and WhatsApp channel adapters.
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
use OCA\Pipelinq\Service\ContactmomentService;
use Throwable;

/**
 * Contact-moment service, phone, tenant and schema-slug reads of a channel
 * adapter. The host class provides IAppConfig `$appConfig` and
 * ContainerInterface `$container` properties.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait ChannelAdapterLookups {

	/**
	 * The contact moment service, resolved lazily; null when it is absent.
	 *
	 * @return ContactmomentService|null The service or null.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function contactmoments(): ?ContactmomentService {
		try {
			$service = $this->container->get('OCA\\Pipelinq\\Service\\ContactmomentService');
		} catch (Throwable $e) {
			return null;
		}

		if (($service instanceof ContactmomentService) === false) {
			return null;
		}

		return $service;
	}//end contactmoments()

	/**
	 * Extract a phone number from a contact row.
	 *
	 * @param array<string, mixed> $contact Contact row.
	 *
	 * @return string Phone number or empty.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function extractPhone(array $contact): string {
		foreach (['phoneNumber', 'mobile', 'telephone', 'phone'] as $key) {
			$value = ($contact[$key] ?? null);
			if (is_string($value) === true && $value !== '') {
				return $value;
			}
		}

		return '';
	}//end extractPhone()

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
