<?php

/**
 * Contract of PortalTenantService
 *
 * The public surface of PortalTenantService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

use OCA\Pipelinq\Service\Portal\PortalException;

/**
 * Contract of PortalTenantService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalTenantServiceInterface {

	/**
	 * The default tenant id for single-tenant instances.
	 *
	 * @var string
	 */
	public const DEFAULT_TENANT = 'default';

	/**
	 * The full admin view of a tenant's config: the stored record, or the
	 * defaults the portal applies when no record exists yet.
	 *
	 * The admin screen reads this before it saves, because saveConfig()
	 * replaces the whole record: an admin who could not read the current
	 * values back would overwrite them blind.
	 *
	 * @param string $tenantId The tenant id.
	 *
	 * @return array{config: array<string, mixed>, configured: bool} The config and whether it is stored.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function getAdminConfig(string $tenantId): array;

	/**
	 * Load a tenant's full config record, or null when it does not exist.
	 *
	 * @param string $tenantId The tenant id.
	 *
	 * @return array<string, mixed>|null The config, or null.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function getConfig(string $tenantId): ?array;

	/**
	 * The client-safe public branding for a tenant (no admin/security fields).
	 *
	 * @param string $tenantId The tenant id.
	 *
	 * @return array<string, mixed> The public config (defaults when unconfigured).
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function getPublicConfig(string $tenantId): array;

	/**
	 * Every stored tenant config, sorted by display name. A record without a
	 * status reads as live.
	 *
	 * @return array<int, array<string, mixed>> The configs.
		 * @spec openspec/changes/portal-tenants-on-one-admin-tab/specs/customer-portal/spec.md#requirement-the-admin-tab-lists-every-portal-tenant
	 */
	public function listConfigs(): array;

	/**
	 * Assert a feature is enabled or throw a 404 featureNotEnabled.
	 *
	 * @param string $tenantId The tenant id.
	 * @param string $feature The feature key.
	 *
	 * @return void
	 *
	 * @throws PortalException When the feature is disabled.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function requireFeature(string $tenantId, string $feature): void;

	/**
	 * Persist tenant config (admin), validating brand-colour contrast first.
	 *
	 * @param string $tenantId The tenant id.
	 * @param array<string, mixed> $config The config to save (tenantId forced).
	 *
	 * @return array<string, mixed> The saved config.
	 *
	 * @throws PortalException When contrast is below AA.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function saveConfig(string $tenantId, array $config): array;
}//end interface
