<?php

/**
 * Pipelinq PortalTenantService.
 *
 * Owns portal_tenant_config: resolving the tenant for a request (custom domain,
 * subdomain, or the X-Portal-Tenant widget header), reading the public branding
 * a portal page renders, and persisting admin config with WCAG AA contrast
 * validation on the brand colours. Tenant resolution is server-side only — a
 * client never supplies a tenant id in a body or query string (REQ-002), so it
 * can never read across the tenant boundary by parameter tampering.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Portal
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/customer-portal/specs.md#REQ-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use OCA\Pipelinq\Util\ContrastRatioCalculator;
use OCP\AppFramework\Http;

/**
 * Resolves and persists portal tenant configuration.
 */
class PortalTenantService {
	/**
	 * Schema slug for tenant config.
	 *
	 * @var string
	 */
	private const SCHEMA = 'portalTenantConfig';

	/**
	 * The default tenant id for single-tenant instances.
	 *
	 * @var string
	 */
	public const DEFAULT_TENANT = 'default';

	/**
	 * Public (client-safe) config fields. Everything else is admin-only.
	 *
	 * @var array<int, string>
	 */
	private const PUBLIC_FIELDS = [
		'tenantId',
		'displayName',
		'logoFileId',
		'faviconFileId',
		'brandPrimaryColor',
		'brandSecondaryColor',
		'brandBackgroundColor',
		'enabledFeatures',
		'b2bEnabled',
		'b2cEnabled',
		'supportEmail',
		'supportPhone',
		'termsVersion',
		'privacyVersion',
	];

	/**
	 * Constructor.
	 *
	 * @param PortalObjectRepository $repository The portal object repository.
	 * @param ContrastRatioCalculator $contrast The contrast calculator.
	 */
	public function __construct(
		private PortalObjectRepository $repository,
		private ContrastRatioCalculator $contrast,
	) {
	}//end __construct()

	/**
	 * Resolve the tenant id for a request from server-trusted signals only.
	 *
	 * Resolution order: custom-domain match, then subdomain match, then an
	 * explicit widget header. Falls back to the single-instance default tenant.
	 * Never reads a tenant id from a request body or query string.
	 *
	 * @param string|null $host The request hostname.
	 * @param string|null $widgetTenant The X-Portal-Tenant header value (widget mode).
	 *
	 * @return string The resolved tenant id.
	 */
	public function resolveTenantId(?string $host, ?string $widgetTenant): string {
		$host = strtolower(trim((string)$host));

		if ($host !== '') {
			$byDomain = $this->repository->findOneBy(self::SCHEMA, ['customDomain' => $host]);
			if ($byDomain !== null) {
				return (string)($byDomain['tenantId'] ?? self::DEFAULT_TENANT);
			}

			$subdomain = explode('.', $host)[0];
			if ($subdomain !== '' && $subdomain !== $host) {
				$bySubdomain = $this->repository->findOneBy(self::SCHEMA, ['subdomain' => $subdomain]);
				if ($bySubdomain !== null) {
					return (string)($bySubdomain['tenantId'] ?? self::DEFAULT_TENANT);
				}
			}
		}

		if ($widgetTenant !== null && trim($widgetTenant) !== '') {
			return trim($widgetTenant);
		}

		return self::DEFAULT_TENANT;
	}//end resolveTenantId()

	/**
	 * Load a tenant's full config record, or null when it does not exist.
	 *
	 * @param string $tenantId The tenant id.
	 *
	 * @return array<string, mixed>|null The config, or null.
	 */
	public function getConfig(string $tenantId): ?array {
		return $this->repository->findOneBy(self::SCHEMA, ['tenantId' => $tenantId]);
	}//end getConfig()

	/**
	 * The client-safe public branding for a tenant (no admin/security fields).
	 *
	 * @param string $tenantId The tenant id.
	 *
	 * @return array<string, mixed> The public config (defaults when unconfigured).
	 */
	public function getPublicConfig(string $tenantId): array {
		$config = ($this->getConfig(tenantId: $tenantId) ?? $this->defaults(tenantId: $tenantId));
		$public = [];
		foreach (self::PUBLIC_FIELDS as $field) {
			if (array_key_exists($field, $config) === true) {
				$public[$field] = $config[$field];
			}
		}

		$public['tenantId'] = $tenantId;
		return $public;
	}//end getPublicConfig()

	/**
	 * Persist tenant config (admin), validating brand-colour contrast first.
	 *
	 * @param string $tenantId The tenant id.
	 * @param array<string, mixed> $config The config to save (tenantId forced).
	 *
	 * @return array<string, mixed> The saved config.
	 *
	 * @throws PortalException When contrast is below AA.
	 */
	public function saveConfig(string $tenantId, array $config): array {
		$config['tenantId'] = $tenantId;
		$this->assertContrast(config: $config);

		$existing = $this->getConfig(tenantId: $tenantId);
		$id = null;
		if ($existing !== null) {
			$id = $this->repository->idOf(object: $existing);
		}

		return $this->repository->save(self::SCHEMA, $config, $id);
	}//end saveConfig()

	/**
	 * Whether a feature is enabled for a tenant.
	 *
	 * @param string $tenantId The tenant id.
	 * @param string $feature The feature key (e.g. invoices).
	 *
	 * @return bool True when enabled.
	 */
	public function isFeatureEnabled(string $tenantId, string $feature): bool {
		$config = ($this->getConfig(tenantId: $tenantId) ?? $this->defaults(tenantId: $tenantId));
		$features = ($config['enabledFeatures'] ?? []);
		return is_array($features) === true && in_array($feature, $features, true) === true;
	}//end isFeatureEnabled()

	/**
	 * Assert a feature is enabled or throw a 404 featureNotEnabled.
	 *
	 * @param string $tenantId The tenant id.
	 * @param string $feature The feature key.
	 *
	 * @return void
	 *
	 * @throws PortalException When the feature is disabled.
	 */
	public function requireFeature(string $tenantId, string $feature): void {
		if ($this->isFeatureEnabled(tenantId: $tenantId, feature: $feature) === false) {
			throw new PortalException(
				Http::STATUS_NOT_FOUND,
				'featureNotEnabled',
				'Deze functie is niet beschikbaar.'
			);
		}
	}//end requireFeature()

	/**
	 * Whether MFA is enforced tenant-wide.
	 *
	 * @param string $tenantId The tenant id.
	 *
	 * @return bool True when enforced.
	 */
	public function mfaEnforced(string $tenantId): bool {
		$config = $this->getConfig(tenantId: $tenantId);
		return $config !== null && ($config['mfaEnforced'] ?? false) === true;
	}//end mfaEnforced()

	/**
	 * The session TTL (hours) for a tenant.
	 *
	 * @param string $tenantId The tenant id.
	 *
	 * @return int The TTL in hours.
	 */
	public function sessionTtlHours(string $tenantId): int {
		$config = $this->getConfig(tenantId: $tenantId);
		$ttl = (int)($config['sessionTtlHours'] ?? PortalSessionManager::DEFAULT_TTL_HOURS);
		if ($ttl > 0) {
			return $ttl;
		}

		return PortalSessionManager::DEFAULT_TTL_HOURS;
	}//end sessionTtlHours()

	/**
	 * Whether a request Origin is allowed to embed the widget for a tenant.
	 *
	 * @param string $tenantId The tenant id.
	 * @param string|null $origin The request Origin header.
	 *
	 * @return bool True when widget embedding from this origin is allowed.
	 */
	public function isWidgetOriginAllowed(string $tenantId, ?string $origin): bool {
		$config = $this->getConfig(tenantId: $tenantId);
		if ($config === null || ($config['widgetEmbedAllowed'] ?? false) !== true) {
			return false;
		}

		if ($origin === null || trim($origin) === '') {
			return false;
		}

		$allowed = ($config['widgetAllowedOrigins'] ?? []);
		return is_array($allowed) === true && in_array(
			rtrim(trim($origin), '/'),
			array_map(
				static fn ($value): string => rtrim((string)$value, '/'),
				$allowed
			),
			true
		) === true;
	}//end isWidgetOriginAllowed()

	/**
	 * Whether self-signup is allowed for a tenant.
	 *
	 * NOT AN ACTIVE GUARD — it has no callers, because the portal has no
	 * self-signup endpoint: no controller or service creates a
	 * `portalAccount` (`PortalAccountService` only closes existing accounts;
	 * `PortalAuthController` only authenticates them). It reads a real
	 * schema field (`register.d/40-portal.json`), so a tenant admin can set
	 * `selfSignupAllowed` and nothing consumes it.
	 *
	 * Kept rather than deleted because the flow is a plausible near-term
	 * feature on a live schema field — but it must not be mistaken for
	 * enforcement, and the fix for the gate-6 finding it raises is to BUILD
	 * the flow and call this from it, never to invent an account-creation
	 * endpoint just to give the predicate a caller. See pipelinq#401 and the
	 * archived `2026-07-16-orphan-auth-remediation` change.
	 *
	 * @param string $tenantId The tenant id.
	 *
	 * @return bool True when self-signup is allowed.
	 */
	public function isSelfSignupAllowed(string $tenantId): bool {
		$config = $this->getConfig(tenantId: $tenantId);
		return $config !== null && ($config['selfSignupAllowed'] ?? false) === true;
	}//end isSelfSignupAllowed()

	/**
	 * Validate brand-colour contrast, throwing a 422 below AA.
	 *
	 * @param array<string, mixed> $config The config being saved.
	 *
	 * @return void
	 *
	 * @throws PortalException When contrast is insufficient.
	 */
	private function assertContrast(array $config): void {
		$primary = ($config['brandPrimaryColor'] ?? null);
		$background = ($config['brandBackgroundColor'] ?? '#FFFFFF');
		if ($primary === null || $primary === '') {
			return;
		}

		try {
			$ratio = $this->contrast->calculate(color1: (string)$primary, color2: (string)$background);
		} catch (\InvalidArgumentException $e) {
			throw new PortalException(
				Http::STATUS_UNPROCESSABLE_ENTITY,
				'invalidColor',
				'Ongeldige kleurwaarde. Gebruik een hex-kleur zoals #21468B.'
			);
		}

		if ($this->contrast->meetsAaStandard(ratio: $ratio) === false) {
			throw new PortalException(
				Http::STATUS_UNPROCESSABLE_ENTITY,
				'contrastRatioBelowMinimum',
				sprintf(
					'Kleurcontrast is onvoldoende (%s:1). Minimaal vereist: 4.5:1. Kies een donkerder of lichter kleur.',
					$ratio
				),
				['contrastRatio' => $ratio, 'requiredMinimum' => ContrastRatioCalculator::AA_MINIMUM]
			);
		}
	}//end assertContrast()

	/**
	 * Default config for an unconfigured tenant.
	 *
	 * @param string $tenantId The tenant id.
	 *
	 * @return array<string, mixed> The defaults.
	 */
	private function defaults(string $tenantId): array {
		return [
			'tenantId' => $tenantId,
			'displayName' => 'Klantportaal',
			'brandPrimaryColor' => '#21468B',
			'brandSecondaryColor' => '#FFFFFF',
			'brandBackgroundColor' => '#FFFFFF',
			'enabledFeatures' => ['invoices', 'contracts', 'orders', 'requests', 'documents', 'profile'],
			'b2bEnabled' => true,
			'b2cEnabled' => true,
		];
	}//end defaults()
}//end class
