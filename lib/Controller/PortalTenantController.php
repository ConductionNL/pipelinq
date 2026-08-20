<?php

/**
 * Pipelinq PortalTenantController.
 *
 * The single public (pre-login) portal endpoint: it returns only the tenant's
 * client-safe branding (display name, logo, colours, enabled features, support
 * contact) so the login page can render in the tenant's identity before any
 * authentication. Tenant identity is resolved server-side from the host /
 * widget header — never from a client-supplied tenant id — and no security,
 * widget-origin or audit fields are exposed (ADR-005, REQ-002).
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
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

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Service\Portal\PortalRequestGuard;
use OCA\Pipelinq\Service\Portal\PortalTenantService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Public tenant-branding endpoint.
 */
class PortalTenantController extends PortalApiController {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalRequestGuard $guard The portal guard.
	 * @param LoggerInterface $logger The logger.
	 * @param PortalTenantService $tenant The tenant service.
	 */
	public function __construct(
		IRequest $request,
		PortalRequestGuard $guard,
		LoggerInterface $logger,
		private PortalTenantService $tenant,
	) {
		parent::__construct(request: $request, guard: $guard, logger: $logger);
	}//end __construct()

	/**
	 * Get the public branding for the resolved tenant (no auth required).
	 *
	 * @return JSONResponse The public branding.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	#[AnonRateLimit(limit: 240, period: 60)]
	public function config(): JSONResponse {
		return $this->guarded(
			handler: function (): array {
				$tenantId = $this->requireTenant();
				return [$this->tenant->getPublicConfig(tenantId: $tenantId), Http::STATUS_OK];
			}
		);
	}//end config()
}//end class
