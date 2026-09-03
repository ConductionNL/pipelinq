<?php

/**
 * Pipelinq PortalAuthController.
 *
 * Unauthenticated entry points for the portal auth domain: login (with optional
 * TOTP), logout, session extension, password-reset request/complete, and MFA
 * enrolment/verification. Every endpoint is `@PublicPage`; the ones that need an
 * identity authenticate from the bearer token, the ones that establish identity
 * (login, reset-request) are uniform-response and rate-limited by the auth
 * service. Bearer auth carries no ambient cookie, so these POSTs are not
 * CSRF-able and are marked `@NoCSRFRequired` deliberately (ADR-005).
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

use OCA\Pipelinq\Service\Portal\PasswordResetService;
use OCA\Pipelinq\Service\Portal\PortalAuthService;
use OCA\Pipelinq\Service\Portal\PortalMfaService;
use OCA\Pipelinq\Service\Portal\PortalObjectRepository;
use OCA\Pipelinq\Service\Portal\PortalRequestGuard;
use OCA\Pipelinq\Service\Portal\PortalSessionManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Portal authentication endpoints.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Aggregates the auth-flow services
 *  (auth, sessions, MFA, reset, repository, guard) a login controller needs.
 *
 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
 *   sessions, tokens, delegation, documents, invoices, orders, exports and
 *   audit are all unspecified
 */
class PortalAuthController extends PortalApiController {
	/**
	 * Schema slug for accounts.
	 *
	 * @var string
	 */
	private const ACCOUNT_SCHEMA = 'portalAccount';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalRequestGuard $guard The portal guard.
	 * @param LoggerInterface $logger The logger.
	 * @param PortalAuthService $auth The auth service.
	 * @param PortalSessionManager $sessions The session manager.
	 * @param PortalMfaService $mfa The MFA service.
	 * @param PasswordResetService $reset The password-reset service.
	 * @param PortalObjectRepository $repository The portal object repository.
	 */
	public function __construct(
		IRequest $request,
		PortalRequestGuard $guard,
		LoggerInterface $logger,
		private PortalAuthService $auth,
		private PortalSessionManager $sessions,
		private PortalMfaService $mfa,
		private PasswordResetService $reset,
		private PortalObjectRepository $repository,
	) {
		parent::__construct(request: $request, guard: $guard, logger: $logger);
	}//end __construct()

	/**
	 * Authenticate with email + password (+ optional TOTP).
	 *
	 * The rate limit is an IP-scoped ceiling ALONGSIDE the account-scoped
	 * lockout, not instead of it. `PortalAuthService` already arms a
	 * sliding-window lockout after repeated failures on ONE account — but an
	 * account lockout cannot see PASSWORD SPRAYING, where one IP tries one
	 * password against many accounts and never trips any single account's
	 * counter. That is the gap this closes.
	 *
	 * @return JSONResponse The login result (token or mfa-required marker).
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	#[AnonRateLimit(limit: 20, period: 60)]
	public function login(): JSONResponse {
		return $this->guarded(
			handler: function (): array {
				$tenantId = $this->requireTenant();
				$totpCode = $this->strParam(name: 'totpCode');
				if ($totpCode === '') {
					$totpCode = null;
				}

				$result = $this->auth->login(
					email: $this->strParam(name: 'email'),
					password: (string)$this->request->getParam('password', ''),
					tenantId: $tenantId,
					totpCode: $totpCode,
					ipHash: $this->guard->ipHash(request: $this->request),
					userAgentHash: $this->guard->userAgentHash(request: $this->request)
				);

				return [$result, Http::STATUS_OK];
			}
		);
	}//end login()

	/**
	 * Revoke the current session.
	 *
	 * @return JSONResponse The logout acknowledgement.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	#[AnonRateLimit(limit: 60, period: 60)]
	public function logout(): JSONResponse {
		return $this->guarded(
			handler: function (): array {
				$ctx = $this->requireSession();
				$sessionId = (string)$this->repository->idOf(object: $ctx['session']);
				$this->sessions->revokeSession(sessionId: $sessionId, reason: 'logout');
				return [['status' => 'logged-out'], Http::STATUS_OK];
			}
		);
	}//end logout()

	/**
	 * Extend the current session's TTL.
	 *
	 * @return JSONResponse The new expiry.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	#[AnonRateLimit(limit: 60, period: 60)]
	public function extendSession(): JSONResponse {
		return $this->guarded(
			handler: function (): array {
				$ctx = $this->requireSession();
				$sessionId = (string)$this->repository->idOf(object: $ctx['session']);
				$updated = $this->sessions->extendSessionOrThrow(sessionId: $sessionId);

				return [['expiresAt' => ($updated['expiresAt'] ?? null)], Http::STATUS_OK];
			}
		);
	}//end extendSession()

	/**
	 * Begin a password reset (uniform response — no account enumeration).
	 *
	 * The AnonRateLimit below is deliberately tight: this one sends mail to an
	 * address the caller supplies, so an unbounded caller can use it to
	 * mail-bomb a third party.
	 *
	 * @return JSONResponse The uniform acknowledgement.
	 *
	 * @NoAdminRequired
	 * The rate limit is deliberately tight: this one sends mail to an address the
	 * caller supplies, so an unbounded caller can use it to mail-bomb a third
	 * party.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	#[AnonRateLimit(limit: 10, period: 60)]
	public function passwordResetRequest(): JSONResponse {
		return $this->guarded(
			handler: function (): array {
				$tenantId = $this->requireTenant();
				$this->reset->requestReset(email: $this->strParam(name: 'email'), tenantId: $tenantId);
				return [['status' => 'ok'], Http::STATUS_OK];
			}
		);
	}//end passwordResetRequest()

	/**
	 * Complete a password reset with a token + new password.
	 *
	 * The AnonRateLimit below is deliberately tight: the token is the only
	 * thing standing between a caller and an account takeover, so the ceiling
	 * bounds how fast one can be guessed.
	 *
	 * @return JSONResponse The result.
	 *
	 * @NoAdminRequired
	 * The rate limit is deliberately tight: the token is the only thing standing
	 * between a caller and an account takeover, so the ceiling bounds how fast
	 * one can be guessed.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	#[AnonRateLimit(limit: 20, period: 60)]
	public function passwordReset(): JSONResponse {
		return $this->guarded(
			handler: function (): array {
				$tenantId = $this->requireTenant();
				$this->reset->resetPassword(
					$this->strParam(name: 'token'),
					(string)$this->request->getParam('password', ''),
					$tenantId
				);
				return [['status' => 'password-reset'], Http::STATUS_OK];
			}
		);
	}//end passwordReset()

	/**
	 * Begin TOTP enrolment for the authenticated account (returns the otpauth
	 * URI to render as a QR code; the secret is only stored on verification).
	 *
	 * @return JSONResponse The enrolment material.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	#[AnonRateLimit(limit: 20, period: 60)]
	public function mfaEnroll(): JSONResponse {
		return $this->guarded(
			handler: function (): array {
				$ctx = $this->requireSession();
				$account = $ctx['account'];
				$secret = $this->mfa->generateSecret();
				$uri = $this->mfa->provisioningUri(
					secret: $secret,
					accountLabel: (string)($account['email'] ?? 'portal'),
					issuer: 'Pipelinq'
				);

				// 🔴 PENDING MEANS PENDING. DO NOT TOUCH THE LIVE FACTOR.
				//
				// This comment used to sit above a write to `mfaSecret` itself,
				// with `mfaEnabled = false` beside it. On an account that
				// already had a verified second factor that was a DOWNGRADE
				// performed before anything was proven: the working secret was
				// destroyed and MFA switched off, so whoever called this --
				// including someone holding only a stolen password -- could
				// then log in with the password alone.
				//
				// Enrolment is a proposal. It may not weaken the account until
				// mfaVerify() has seen a code that proves the authenticator
				// really holds the new secret; only then is it promoted.
				$account['mfaPendingSecret'] = $this->mfa->encryptSecret(secret: $secret);
				$this->repository->save(self::ACCOUNT_SCHEMA, $account, $ctx['accountId']);

				return [['secret' => $secret, 'otpauthUri' => $uri], Http::STATUS_OK];
			}
		);
	}//end mfaEnroll()

	/**
	 * Verify a TOTP code to activate MFA on the account.
	 *
	 * The AnonRateLimit below is the tightest in this controller: a TOTP code
	 * is six digits, so the whole keyspace is a million guesses. Without a
	 * ceiling that is minutes of work.
	 *
	 * @return JSONResponse The result.
	 *
	 * @NoAdminRequired
	 * The tightest rate limit in this controller: a TOTP code is six digits, so
	 * the whole keyspace is a million guesses. Without a ceiling that is minutes
	 * of work.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	#[AnonRateLimit(limit: 10, period: 60)]
	public function mfaVerify(): JSONResponse {
		return $this->guarded(
			handler: function (): array {
				$ctx = $this->requireSession();
				$account = $ctx['account'];

				// Verify against the PENDING secret when an enrolment is in
				// flight, and fall back to the live one only when there is no
				// pending enrolment — that keeps re-verification working for an
				// account enrolled before this field existed.
				$pending = ($account['mfaPendingSecret'] ?? null);
				$candidate = ($pending ?? ($account['mfaSecret'] ?? null));
				if ($this->mfa->verifyCode(encryptedSecret: $candidate, code: $this->strParam(name: 'code')) === false) {
					return [['errorCode' => 'invalidMfaCode', 'message' => 'Ongeldige code.'], Http::STATUS_BAD_REQUEST];
				}

				// Promote only now: the code proved the authenticator holds it.
				if ($pending !== null && $pending !== '') {
					$account['mfaSecret'] = $pending;
				}

				unset($account['mfaPendingSecret']);
				$account['mfaEnabled'] = true;
				$this->repository->save(self::ACCOUNT_SCHEMA, $account, $ctx['accountId']);
				return [['status' => 'mfa-enabled'], Http::STATUS_OK];
			}
		);
	}//end mfaVerify()
}//end class
