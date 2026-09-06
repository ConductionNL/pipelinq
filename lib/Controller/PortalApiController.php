<?php

/**
 * Pipelinq PortalApiController.
 *
 * Base controller for every `/portal/api/*` endpoint. It centralises the two
 * security-critical behaviours mandated by ADR-005: turning a thrown
 * PortalException into a safe JSON body + correct HTTP status (never a stack
 * trace), and exposing the guard so each action authenticates the customer from
 * the bearer token instead of trusting any client-supplied id. All portal
 * endpoints are `@PublicPage` (no Nextcloud user) but authenticated per-request
 * by the portal session token.
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
 * @spec openspec/changes/customer-portal/specs.md#REQ-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Portal\PortalException;
use OCA\Pipelinq\Service\Portal\PortalRequestGuard;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Shared base for portal API controllers.
 *
 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
 *   sessions, tokens, delegation, documents, invoices, orders, exports and
 *   audit are all unspecified
 */
abstract class PortalApiController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalRequestGuard $guard The portal auth/tenant guard.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		IRequest $request,
		protected PortalRequestGuard $guard,
		protected LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Authenticate the current request and return the portal context.
	 *
	 * @return array{account: array<string, mixed>, accountId: string, session: array<string, mixed>, tenantId: string}
	 *
	 * @throws PortalException When not authenticated.
	 */
	protected function context(): array {
		return $this->guard->authenticate(request: $this->request);
	}//end context()

	/**
	 * Require an authenticated portal session for this request — alias of
	 * {@see self::context()} with a name that documents the auth posture (the
	 * call throws PortalException(STATUS_UNAUTHORIZED) when no valid bearer
	 * session is presented). Used by every portal endpoint that operates on
	 * customer-scoped data.
	 *
	 * @return array{account: array<string, mixed>, accountId: string, session: array<string, mixed>, tenantId: string}
	 *
	 * @throws PortalException When not authenticated.
	 */
	protected function requireSession(): array {
		return $this->context();
	}//end requireSession()

	/**
	 * Require the resolved tenant id for this request — wraps the guard's
	 * server-trusted tenant resolution (host header + X-Portal-Tenant). Used
	 * by pre-session entry points (login, password reset) and tenant-scoped
	 * public configuration so the auth posture is explicit at the call site.
	 *
	 * @return string The tenant id.
	 */
	protected function requireTenant(): string {
		return $this->guard->resolveTenant(request: $this->request);
	}//end requireTenant()

	/**
	 * Run a handler, mapping PortalException to a safe JSON error and any other
	 * throwable to an opaque 500 (no internal detail leaks to the client).
	 *
	 * @param callable $handler The action body returning [body, status].
	 *
	 * @return JSONResponse The response.
	 */
	protected function guarded(callable $handler): JSONResponse {
		try {
			[$body, $status] = $handler();
			return new JSONResponse($body, $status);
		} catch (PortalException $e) {
			return new JSONResponse($e->toBody(), $e->getStatus());
		} catch (\Throwable $e) {
			$this->logger->error('Pipelinq portal: unhandled error', ['exception' => $e->getMessage()]);
			return new JSONResponse(
				['errorCode' => 'serverError', 'message' => 'Er is een fout opgetreden.'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}//end guarded()

	/**
	 * Read a string field from the request body, trimmed.
	 *
	 * @param string $name The field name.
	 * @param string $default The default.
	 *
	 * @return string The value.
	 */
	protected function strParam(string $name, string $default = ''): string {
		$value = $this->request->getParam($name, $default);
		if (is_string($value) === true) {
			return trim($value);
		}

		return $default;
	}//end strParam()

	/**
	 * Read an int field from the request body.
	 *
	 * @param string $name The field name.
	 * @param int $default The default.
	 *
	 * @return int The value.
	 */
	protected function intParam(string $name, int $default = 0): int {
		$value = $this->request->getParam($name, $default);
		if (is_numeric($value) === true) {
			return (int)$value;
		}

		return $default;
	}//end intParam()
}//end class
