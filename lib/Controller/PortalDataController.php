<?php

/**
 * Pipelinq PortalDataController.
 *
 * Read endpoints for the authenticated customer's invoices, contracts and
 * orders. Every action authenticates from the bearer token, checks the tenant
 * has the feature enabled, and delegates to a per-customer-scoped read facade,
 * so a user only ever sees their own (or explicitly delegated) records and a
 * guessed id from another customer/tenant returns 404 — never a different
 * customer's data (ADR-005, REQ-003).
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
 * @spec openspec/changes/customer-portal/specs.md#REQ-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Service\Portal\AbstractPortalReadFacade;
use OCA\Pipelinq\Service\Portal\PortalContractService;
use OCA\Pipelinq\Service\Portal\PortalInvoiceService;
use OCA\Pipelinq\Service\Portal\PortalOrderService;
use OCA\Pipelinq\Service\Portal\PortalRequestGuard;
use OCA\Pipelinq\Service\Portal\PortalTenantService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Per-customer invoice/contract/order read endpoints.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wires the three read facades
 *  plus the tenant feature gate this controller fronts.
 *
 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
 *   sessions, tokens, delegation, documents, invoices, orders, exports and
 *   audit are all unspecified
 */
class PortalDataController extends PortalApiController {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalRequestGuard $guard The portal guard.
	 * @param LoggerInterface $logger The logger.
	 * @param PortalInvoiceService $invoices The invoice facade.
	 * @param PortalContractService $contracts The contract facade.
	 * @param PortalOrderService $orders The order facade.
	 * @param PortalTenantService $tenant The tenant service.
	 */
	public function __construct(
		IRequest $request,
		PortalRequestGuard $guard,
		LoggerInterface $logger,
		private PortalInvoiceService $invoices,
		private PortalContractService $contracts,
		private PortalOrderService $orders,
		private PortalTenantService $tenant,
	) {
		parent::__construct(request: $request, guard: $guard, logger: $logger);
	}//end __construct()

	/**
	 * List the customer's invoices.
	 *
	 * @return JSONResponse The paginated invoices.
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
	public function invoices(): JSONResponse {
		return $this->requireListAccess(facade: $this->invoices, feature: 'invoices');
	}//end invoices()

	/**
	 * Get one invoice by id.
	 *
	 * @param string $id The invoice id.
	 *
	 * @return JSONResponse The invoice, or 404.
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
	public function invoice(string $id): JSONResponse {
		return $this->requireObjectAccess(facade: $this->invoices, feature: 'invoices', id: $id);
	}//end invoice()

	/**
	 * List the customer's contracts.
	 *
	 * @return JSONResponse The paginated contracts.
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
	public function contracts(): JSONResponse {
		return $this->requireListAccess(facade: $this->contracts, feature: 'contracts');
	}//end contracts()

	/**
	 * Get one contract by id.
	 *
	 * @param string $id The contract id.
	 *
	 * @return JSONResponse The contract, or 404.
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
	public function contract(string $id): JSONResponse {
		return $this->requireObjectAccess(facade: $this->contracts, feature: 'contracts', id: $id);
	}//end contract()

	/**
	 * List the customer's orders.
	 *
	 * @return JSONResponse The paginated orders.
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
	public function orders(): JSONResponse {
		return $this->requireListAccess(facade: $this->orders, feature: 'orders');
	}//end orders()

	/**
	 * Get one order by id.
	 *
	 * @param string $id The order id.
	 *
	 * @return JSONResponse The order, or 404.
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
	public function order(string $id): JSONResponse {
		return $this->requireObjectAccess(facade: $this->orders, feature: 'orders', id: $id);
	}//end order()

	/**
	 * Shared list handler: authenticate, feature-gate, paginate.
	 *
	 * @param AbstractPortalReadFacade $facade The read facade.
	 * @param string $feature The feature key.
	 *
	 * @return JSONResponse The paginated list.
	 */
	private function requireListAccess(AbstractPortalReadFacade $facade, string $feature): JSONResponse {
		return $this->guarded(
			handler: function () use ($facade, $feature): array {
				$ctx = $this->requireSession();
				$this->tenant->requireFeature(tenantId: $ctx['tenantId'], feature: $feature);
				$page = $facade->getForAccount(
					$ctx['account'],
					$this->intParam(name: 'page', default: 1),
					$this->intParam(name: 'perPage', default: 10)
				);
				return [$page, Http::STATUS_OK];
			}
		);
	}//end requireListAccess()

	/**
	 * Shared detail handler: authenticate, feature-gate, fetch-or-404.
	 *
	 * @param AbstractPortalReadFacade $facade The read facade.
	 * @param string $feature The feature key.
	 * @param string $id The object id.
	 *
	 * @return JSONResponse The object, or 404.
	 */
	private function requireObjectAccess(AbstractPortalReadFacade $facade, string $feature, string $id): JSONResponse {
		return $this->guarded(
			handler: function () use ($facade, $feature, $id): array {
				$ctx = $this->requireSession();
				$this->tenant->requireFeature(tenantId: $ctx['tenantId'], feature: $feature);
				$object = $facade->getOneForAccount($ctx['account'], $id);
				if ($object === null) {
					return [['errorCode' => 'notFound', 'message' => 'Niet gevonden.'], Http::STATUS_NOT_FOUND];
				}

				return [$object, Http::STATUS_OK];
			}
		);
	}//end requireObjectAccess()
}//end class
