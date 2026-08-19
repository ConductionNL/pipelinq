<?php

/**
 * Pipelinq PortalRequestGuard.
 *
 * The single authentication + tenant-resolution gate every portal controller
 * calls before touching customer data. It resolves the tenant from server-side
 * signals only (host / subdomain / X-Portal-Tenant), authenticates the customer
 * from the `Authorization: Bearer <token>` header via the session manager, loads
 * the bound account, and refuses anything that is not a live, non-closed
 * session. Controllers therefore NEVER trust a query-param id for identity —
 * the acting account is always derived from the verified token (ADR-005).
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
 * @spec openspec/changes/customer-portal/specs.md#REQ-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use OCP\AppFramework\Http;
use OCP\IRequest;

/**
 * Resolves tenant + authenticates the portal customer for a request.
 */
class PortalRequestGuard
{
    /**
     * Schema slug for accounts.
     *
     * @var string
     */
    private const ACCOUNT_SCHEMA = 'portalAccount';

    /**
     * Constructor.
     *
     * @param PortalObjectRepository $repository The portal object repository.
     * @param PortalSessionManager   $sessions   The session manager.
     * @param PortalTenantService    $tenant     The tenant service.
     */
    public function __construct(
        private PortalObjectRepository $repository,
        private PortalSessionManager $sessions,
        private PortalTenantService $tenant,
    ) {
    }//end __construct()

    /**
     * Resolve the tenant id for a request from server-trusted signals only.
     *
     * In widget mode (tenant asserted via the X-Portal-Tenant header) the
     * request's Origin MUST be in the tenant's widget-origin allow-list, or a
     * 403 PortalException is thrown — the single cross-origin embedding gate.
     *
     * @param IRequest $request The incoming request.
     *
     * @return string The resolved tenant id.
     *
     * @throws PortalException When a widget-mode request's Origin is not allow-listed for the tenant.
     *
     * @spec openspec/specs/customer-portal/spec.md#requirement-widget-mode-requests-are-gated-by-the-tenant-s-origin-allow-list-req-portal-origin
     */
    public function resolveTenant(IRequest $request): string
    {
        $widgetTenant = $this->headerOrNull(request: $request, name: 'X-Portal-Tenant');

        $tenantId = $this->tenant->resolveTenantId(
            $request->getServerHost(),
            $widgetTenant
        );

        // Widget mode (the tenant is asserted by an embedded widget via the
        // X-Portal-Tenant header) is the only cross-origin entry point into the
        // portal. Enforce the tenant's widget-origin allow-list here, at the
        // single tenant-resolution gate every portal endpoint passes through,
        // so no portal action (login, data read, request create) can be driven
        // from a site the tenant has not allow-listed. Host/subdomain mode (no
        // X-Portal-Tenant header) is first-party and unaffected — fail-closed
        // only for the widget path. isWidgetOriginAllowed() itself returns
        // false when widgetEmbedAllowed is off, so a tenant that never enabled
        // embedding rejects every widget-mode request.
        if ($widgetTenant !== null && trim($widgetTenant) !== '') {
            $origin = $this->headerOrNull(request: $request, name: 'Origin');
            if ($this->tenant->isWidgetOriginAllowed(tenantId: $tenantId, origin: $origin) === false) {
                throw new PortalException(
                    Http::STATUS_FORBIDDEN,
                    'originNotAllowed',
                    'Deze widget mag niet vanaf deze locatie worden gebruikt.'
                );
            }
        }

        return $tenantId;
    }//end resolveTenant()

    /**
     * Authenticate the customer for a protected request.
     *
     * Returns a context bundle {account, accountId, session, tenantId} on
     * success, or throws a 401 PortalException — never returns null and never
     * falls open.
     *
     * @param IRequest $request The incoming request.
     *
     * @return array{account: array<string, mixed>, accountId: string, session: array<string, mixed>, tenantId: string}
     *
     * @throws PortalException On any authentication failure.
     */
    public function authenticate(IRequest $request): array
    {
        $tenantId = $this->resolveTenant(request: $request);
        $token    = $this->bearerToken(request: $request);

        $session = $this->sessions->validateSession(token: $token, tenantId: $tenantId);
        if ($session === null) {
            throw new PortalException(Http::STATUS_UNAUTHORIZED, 'unauthenticated', 'Niet ingelogd.');
        }

        $accountId = (string) ($session['accountId'] ?? '');
        $account   = $this->repository->find(self::ACCOUNT_SCHEMA, $accountId);
        if ($account === null || ($account['status'] ?? 'active') === 'closed') {
            throw new PortalException(Http::STATUS_UNAUTHORIZED, 'unauthenticated', 'Niet ingelogd.');
        }

        return [
            'account'   => $account,
            'accountId' => $accountId,
            'session'   => $session,
            'tenantId'  => $tenantId,
        ];
    }//end authenticate()

    /**
     * Hash of the client IP (for audit; never stores the raw IP).
     *
     * @param IRequest $request The request.
     *
     * @return string The IP hash.
     */
    public function ipHash(IRequest $request): string
    {
        return hash('sha256', $request->getRemoteAddress());
    }//end ipHash()

    /**
     * Hash of the client user agent (for audit).
     *
     * @param IRequest $request The request.
     *
     * @return string The user-agent hash.
     */
    public function userAgentHash(IRequest $request): string
    {
        return hash('sha256', $this->headerOrNull(request: $request, name: 'User-Agent') ?? '');
    }//end userAgentHash()

    /**
     * Extract the bearer token from the Authorization header, or null.
     *
     * @param IRequest $request The request.
     *
     * @return string|null The token, or null.
     */
    private function bearerToken(IRequest $request): ?string
    {
        $header = $request->getHeader('Authorization');
        if ($header === '' || stripos($header, 'bearer ') !== 0) {
            return null;
        }

        $token = trim(substr($header, 7));
        if ($token === '') {
            return null;
        }

        return $token;
    }//end bearerToken()

    /**
     * Return a header value, or null when empty.
     *
     * @param IRequest $request The request.
     * @param string   $name    The header name.
     *
     * @return string|null The header value, or null.
     */
    private function headerOrNull(IRequest $request, string $name): ?string
    {
        $value = $request->getHeader($name);
        if ($value === '') {
            return null;
        }

        return $value;
    }//end headerOrNull()
}//end class
