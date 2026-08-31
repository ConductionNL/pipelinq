<?php

/**
 * Pipelinq PortalAdminController.
 *
 * Tenant administration for Nextcloud admins (DPO workflow): save tenant config
 * with WCAG AA contrast validation, list a tenant's portal accounts, and read
 * the full tenant audit trail. These endpoints carry no `@PublicPage` /
 * `@NoAdminRequired` annotation, so Nextcloud's SecurityMiddleware enforces
 * admin-only access by default; the controller additionally asserts admin in the
 * body as defence in depth. They are NOT part of the public portal auth domain
 * (ADR-005, REQ-002 / REQ-010).
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

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Portal\PortalAuditService;
use OCA\Pipelinq\Service\Portal\PortalException;
use OCA\Pipelinq\Service\Portal\PortalObjectRepository;
use OCA\Pipelinq\Service\Portal\PortalTenantService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Admin/DPO tenant management endpoints (Nextcloud admin only).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Aggregates the tenant, audit and
 *  account stores plus the admin gate this management surface needs.
 *
 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
 *   sessions, tokens, delegation, documents, invoices, orders, exports and
 *   audit are all unspecified
 */
class PortalAdminController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalTenantService $tenant The tenant service.
	 * @param PortalAuditService $audit The audit service.
	 * @param PortalObjectRepository $repository The portal object repository.
	 * @param IUserSession $userSession The user session.
	 * @param IGroupManager $groupManager The group manager.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		IRequest $request,
		private PortalTenantService $tenant,
		private PortalAuditService $audit,
		private PortalObjectRepository $repository,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Save tenant config (contrast-validated).
	 *
	 * @auth admin-only Writes tenant-wide portal configuration; the body additionally enforces it through adminGuarded().
	 *
	 * @return JSONResponse The saved config, or an error.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function saveConfig(): JSONResponse {
		return $this->adminGuarded(
			handler: function (): array {
				$tenantId = $this->tenantId();
				$config = $this->request->getParam('config', []);
				if (is_array($config) === false) {
					return [['errorCode' => 'badRequest', 'message' => 'Ongeldige configuratie.'], Http::STATUS_BAD_REQUEST];
				}

				$saved = $this->tenant->saveConfig($tenantId, $config);
				return [$saved, Http::STATUS_OK];
			}
		);
	}//end saveConfig()

	/**
	 * List all portal accounts for a tenant (no secrets).
	 *
	 * @auth admin-only Enumerates every portal account on the tenant; the body additionally enforces it through adminGuarded().
	 *
	 * @return JSONResponse The accounts.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function accounts(): JSONResponse {
		return $this->adminGuarded(
			handler: function (): array {
				$accounts = $this->repository->findAll('portalAccount', ['tenantId' => $this->tenantId()]);
				$safe = array_map(
					static fn (array $account): array => [
						'id' => ($account['@self']['id'] ?? $account['id'] ?? null),
						'email' => ($account['email'] ?? null),
						'displayName' => ($account['displayName'] ?? null),
						'accountType' => ($account['accountType'] ?? null),
						'status' => ($account['status'] ?? null),
						'lastLoginAt' => ($account['lastLoginAt'] ?? null),
					],
					$accounts
				);
				return [['accounts' => $safe], Http::STATUS_OK];
			}
		);
	}//end accounts()

	/**
	 * List all audit events for a tenant (DPO).
	 *
	 * @auth admin-only Returns the tenant-wide audit trail used for DPO reporting; the body additionally enforces it through adminGuarded().
	 *
	 * @return JSONResponse The events.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function auditEvents(): JSONResponse {
		return $this->adminGuarded(
			handler: function (): array {
				return [['events' => $this->audit->getForTenant(tenantId: $this->tenantId())], Http::STATUS_OK];
			}
		);
	}//end auditEvents()

	/**
	 * The tenant id from the request (admin endpoints may target any tenant).
	 *
	 * @return string The tenant id.
	 */
	private function tenantId(): string {
		$value = $this->request->getParam('tenantId', PortalTenantService::DEFAULT_TENANT);
		if (is_string($value) === true && trim($value) !== '') {
			return trim($value);
		}

		return PortalTenantService::DEFAULT_TENANT;
	}//end tenantId()

	/**
	 * Run a handler behind an explicit admin assertion, mapping errors safely.
	 *
	 * @param callable $handler The action body returning [body, status].
	 *
	 * @return JSONResponse The response.
	 */
	private function adminGuarded(callable $handler): JSONResponse {
		try {
			$this->assertAdmin();
			[$body, $status] = $handler();
			return new JSONResponse($body, $status);
		} catch (PortalException $e) {
			return new JSONResponse($e->toBody(), $e->getStatus());
		} catch (\Throwable $e) {
			$this->logger->error('Pipelinq portal admin: unhandled error', ['exception' => $e->getMessage()]);
			return new JSONResponse(
				['errorCode' => 'serverError', 'message' => 'Er is een fout opgetreden.'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}//end adminGuarded()

	/**
	 * Assert the current Nextcloud user is an admin.
	 *
	 * @return void
	 *
	 * @throws PortalException When not an admin.
	 */
	private function assertAdmin(): void {
		$user = $this->userSession->getUser();
		if ($user === null || $this->groupManager->isAdmin($user->getUID()) === false) {
			throw new PortalException(status: Http::STATUS_FORBIDDEN, errorCode: 'forbidden', message: 'Geen toegang.');
		}
	}//end assertAdmin()
}//end class
