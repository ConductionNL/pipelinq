<?php

/**
 * Pipelinq PortalAccountController.
 *
 * Self-service profile + account lifecycle for the authenticated customer: read
 * profile, update profile (auditing every changed field, verifying email
 * changes out-of-band), verify a pending email change, request a data export
 * (AVG Art. 15), and request/confirm account closure (AVG Art. 17). Identity is
 * always the bearer-token account; a user can only ever act on their own
 * account (ADR-005, REQ-007 / REQ-010).
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
 * @spec openspec/changes/customer-portal/specs.md#REQ-007
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Service\Portal\PortalAccountService;
use OCA\Pipelinq\Service\Portal\PortalExportService;
use OCA\Pipelinq\Service\Portal\PortalProfileService;
use OCA\Pipelinq\Service\Portal\PortalRequestGuard;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Portal profile and account-lifecycle endpoints.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Aggregates profile, account and
 *  export services this self-service surface fronts.
 */
class PortalAccountController extends PortalApiController {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalRequestGuard $guard The portal guard.
	 * @param LoggerInterface $logger The logger.
	 * @param PortalProfileService $profile The profile service.
	 * @param PortalAccountService $account The account service.
	 * @param PortalExportService $export The export service.
	 */
	public function __construct(
		IRequest $request,
		PortalRequestGuard $guard,
		LoggerInterface $logger,
		private PortalProfileService $profile,
		private PortalAccountService $account,
		private PortalExportService $export,
	) {
		parent::__construct(request: $request, guard: $guard, logger: $logger);
	}//end __construct()

	/**
	 * Read the current account's profile.
	 *
	 * @return JSONResponse The profile.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function profile(): JSONResponse {
		return $this->guarded(
			handler: function (): array {
				$ctx = $this->requireSession();
				return [$this->profile->present($ctx['account']), Http::STATUS_OK];
			}
		);
	}//end profile()

	/**
	 * Update the current account's profile.
	 *
	 * @return JSONResponse The updated profile.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	#[AnonRateLimit(limit: 30, period: 60)]
	public function updateProfile(): JSONResponse {
		return $this->guarded(
			handler: function (): array {
				$ctx = $this->requireSession();
				$changes = [];
				foreach (['displayName', 'phone', 'locale', 'address', 'jobTitle', 'email'] as $field) {
					$value = $this->request->getParam($field, null);
					if ($value !== null) {
						$changes[$field] = $value;
						if (is_string($value) === true) {
							$changes[$field] = trim($value);
						}
					}
				}

				$updated = $this->profile->update($ctx['account'], $ctx['tenantId'], $changes);
				return [$updated, Http::STATUS_OK];
			}
		);
	}//end updateProfile()

	/**
	 * Verify a pending email change with a token.
	 *
	 * The AnonRateLimit below is deliberately tight: a verification token is
	 * guessable in bulk without a ceiling.
	 *
	 * @return JSONResponse The result.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	#[AnonRateLimit(limit: 20, period: 60)]
	public function verifyEmail(): JSONResponse {
		return $this->guarded(
			handler: function (): array {
				$tenantId = $this->requireTenant();
				$ok = $this->profile->verifyEmail($this->strParam(name: 'token'), $tenantId);
				if ($ok === false) {
					return [['errorCode' => 'invalidToken', 'message' => 'Ongeldige of verlopen link.'], Http::STATUS_BAD_REQUEST];
				}

				return [['status' => 'email-verified'], Http::STATUS_OK];
			}
		);
	}//end verifyEmail()

	/**
	 * Request an AVG data export.
	 *
	 * The AnonRateLimit below is deliberately tight: an export is expensive to
	 * produce, so this is a cheap request that buys the caller a lot of server
	 * work.
	 *
	 * @return JSONResponse The export download descriptor.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	#[AnonRateLimit(limit: 10, period: 60)]
	public function requestExport(): JSONResponse {
		return $this->guarded(
			handler: function (): array {
				$ctx = $this->requireSession();
				$result = $this->export->requestExport($ctx['account'], $ctx['tenantId']);
				return [$result, Http::STATUS_ACCEPTED];
			}
		);
	}//end requestExport()

	/**
	 * Request account closure (sends a confirmation email).
	 *
	 * @return JSONResponse The acknowledgement.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	#[AnonRateLimit(limit: 10, period: 60)]
	public function requestClose(): JSONResponse {
		return $this->guarded(
			handler: function (): array {
				$ctx = $this->requireSession();
				$this->account->requestClosure(account: $ctx['account'], tenantId: $ctx['tenantId']);
				return [['status' => 'closure-requested'], Http::STATUS_OK];
			}
		);
	}//end requestClose()

	/**
	 * Confirm account closure with a token.
	 *
	 * @return JSONResponse The result.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	#[AnonRateLimit(limit: 20, period: 60)]
	public function confirmClose(): JSONResponse {
		return $this->guarded(
			handler: function (): array {
				$tenantId = $this->requireTenant();
				$this->account->close(token: $this->strParam(name: 'token'), tenantId: $tenantId);
				return [['status' => 'account-closed'], Http::STATUS_OK];
			}
		);
	}//end confirmClose()
}//end class
