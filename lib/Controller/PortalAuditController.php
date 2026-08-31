<?php

/**
 * Pipelinq PortalAuditController.
 *
 * Lets the authenticated customer read their own audit trail (login history,
 * downloads, profile changes) — scoped strictly to the bearer-token account, so
 * one customer can never read another's audit events. Tenant-wide audit access
 * for a DPO lives on the admin controller behind the Nextcloud admin gate, not
 * here (ADR-005, REQ-010).
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
 * @spec openspec/changes/customer-portal/specs.md#REQ-010
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Service\Portal\PortalAuditService;
use OCA\Pipelinq\Service\Portal\PortalRequestGuard;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Portal own-audit-trail endpoint.
 *
 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
 *   sessions, tokens, delegation, documents, invoices, orders, exports and
 *   audit are all unspecified
 */
class PortalAuditController extends PortalApiController {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalRequestGuard $guard The portal guard.
	 * @param LoggerInterface $logger The logger.
	 * @param PortalAuditService $audit The audit service.
	 */
	public function __construct(
		IRequest $request,
		PortalRequestGuard $guard,
		LoggerInterface $logger,
		private PortalAuditService $audit,
	) {
		parent::__construct(request: $request, guard: $guard, logger: $logger);
	}//end __construct()

	/**
	 * List the current account's own audit events (paginated, newest-first).
	 *
	 * @return JSONResponse The events.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function index(): JSONResponse {
		return $this->guarded(
			handler: function (): array {
				$ctx = $this->requireSession();
				$events = $this->audit->getForAccount($ctx['accountId']);
				$page = max(1, $this->intParam(name: 'page', default: 1));
				$perPage = min(100, max(1, $this->intParam(name: 'perPage', default: 25)));

				return [
					[
						'total' => count($events),
						'page' => $page,
						'perPage' => $perPage,
						'items' => array_slice($events, (($page - 1) * $perPage), $perPage),
					],
					Http::STATUS_OK,
				];
			}
		);
	}//end index()
}//end class
