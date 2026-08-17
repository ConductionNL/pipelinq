<?php

/**
 * Pipelinq PortalDelegationController.
 *
 * B2B delegation management for the authenticated customer: list the grants they
 * have made, grant a colleague a scoped subset of access, and revoke a grant.
 * The granter is always the bearer-token account (never a body field), and the
 * delegation service refuses revoking another account's grant, so a user can
 * only ever manage their own delegations (ADR-005, REQ-003).
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

use OCA\Pipelinq\Service\Portal\PortalDelegationService;
use OCA\Pipelinq\Service\Portal\PortalException;
use OCA\Pipelinq\Service\Portal\PortalRequestGuard;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Portal B2B delegation endpoints.
 */
class PortalDelegationController extends PortalApiController {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalRequestGuard $guard The portal guard.
	 * @param LoggerInterface $logger The logger.
	 * @param PortalDelegationService $delegations The delegation service.
	 */
	public function __construct(
		IRequest $request,
		PortalRequestGuard $guard,
		LoggerInterface $logger,
		private PortalDelegationService $delegations,
	) {
		parent::__construct(request: $request, guard: $guard, logger: $logger);
	}//end __construct()

	/**
	 * List the delegations granted by the current account.
	 *
	 * @return JSONResponse The delegations.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function index(): JSONResponse {
		return $this->guarded(
			handler: function (): array {
				$ctx = $this->requireSession();
				$this->requireB2b(account: $ctx['account']);
				return [['delegations' => $this->delegations->listGrantedBy(granterAccountId: $ctx['accountId'])], Http::STATUS_OK];
			}
		);
	}//end index()

	/**
	 * Grant a delegation to a colleague.
	 *
	 * @return JSONResponse The created delegation.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	// Tight: a delegation grants another party access to this account.
	#[AnonRateLimit(limit: 20, period: 60)]
	public function create(): JSONResponse {
		return $this->guarded(
			handler: function (): array {
				$ctx = $this->requireSession();
				$this->requireB2b(account: $ctx['account']);

				$scopes = $this->request->getParam('scopes', []);
				if (is_array($scopes) === false) {
					$scopes = [];
				}

				$validUntil = $this->strParam(name: 'validUntil');
				if ($validUntil === '') {
					$validUntil = null;
				}

				$delegation = $this->delegations->grant(
					granterAccountId: $ctx['accountId'],
					tenantId: $ctx['tenantId'],
					granteeEmail: $this->strParam(name: 'granteeEmail'),
					scopes: $scopes,
					validUntil: $validUntil
				);
				return [$delegation, Http::STATUS_CREATED];
			}
		);
	}//end create()

	/**
	 * Revoke a delegation owned by the current account.
	 *
	 * @param string $id The delegation id.
	 *
	 * @return JSONResponse The result.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	#[AnonRateLimit(limit: 30, period: 60)]
	public function destroy(string $id): JSONResponse {
		return $this->guarded(
			handler: function () use ($id): array {
				$ctx = $this->requireSession();
				$this->requireB2b(account: $ctx['account']);
				$this->delegations->revoke(delegationId: $id, granterAccountId: $ctx['accountId'], tenantId: $ctx['tenantId']);
				return [['status' => 'revoked'], Http::STATUS_OK];
			}
		);
	}//end destroy()

	/**
	 * Require the account to be a B2B account (delegation is B2B-only).
	 *
	 * @param array<string, mixed> $account The account.
	 *
	 * @return void
	 *
	 * @throws PortalException When the account is not B2B.
	 */
	private function requireB2b(array $account): void {
		if (($account['accountType'] ?? 'b2c') !== 'b2b') {
			throw new PortalException(status: Http::STATUS_FORBIDDEN, errorCode: 'b2bOnly', message: 'Delegatie is alleen voor zakelijke accounts.');
		}
	}//end requireB2b()
}//end class
