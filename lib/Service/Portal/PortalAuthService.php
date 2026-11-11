<?php

/**
 * Pipelinq PortalAuthService.
 *
 * The login authority for the customer portal. Looks an account up by email +
 * tenant, verifies the argon2id password hash via IHasher, enforces a
 * sliding-window lockout after repeated failures (HTTP 429), refuses closed
 * accounts (HTTP 401), and gates MFA when the account or tenant requires it.
 * Every attempt — success or failure — is audited. This is the single most
 * security-critical surface in the app, so it never reveals which factor failed
 * and never returns secrets (ADR-005, REQ-001).
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
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\IHasher;

/**
 * Authenticates portal accounts.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Aggregates the auth collaborators
 *  (repository, hasher, session manager, MFA, audit, tenant, time) a login flow
 *  legitimately needs.
 *
 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
 *   sessions, tokens, delegation, documents, invoices, orders, exports and
 *   audit are all unspecified
 */
class PortalAuthService {
	/**
	 * Schema slug for accounts.
	 *
	 * @var string
	 */
	private const SCHEMA = 'crmPortalAccount';

	/**
	 * Maximum failed attempts before lockout.
	 *
	 * @var int
	 */
	private const MAX_FAILED_ATTEMPTS = 5;

	/**
	 * Lockout window in minutes.
	 *
	 * @var int
	 */
	private const LOCKOUT_MINUTES = 15;

	/**
	 * Constructor.
	 *
	 * @param PortalObjectRepository $repository The portal object repository.
	 * @param IHasher $hasher The password hasher.
	 * @param PortalSessionManager $sessions The session manager.
	 * @param PortalMfaService $mfa The MFA service.
	 * @param PortalAuditService $audit The audit service.
	 * @param PortalTenantService $tenant The tenant service.
	 * @param ITimeFactory $time The time factory.
	 */
	public function __construct(
		private PortalObjectRepository $repository,
		private IHasher $hasher,
		private PortalSessionManager $sessions,
		private PortalMfaService $mfa,
		private PortalAuditService $audit,
		private PortalTenantService $tenant,
		private ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * Hash a plaintext password via the Nextcloud hasher (argon2id default).
	 *
	 * @param string $password The plaintext password.
	 *
	 * @return string The password hash.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function hashPassword(string $password): string {
		return $this->hasher->hash($password);
	}//end hashPassword()

	/**
	 * Authenticate an account and, on success, mint a session.
	 *
	 * Flow: tenant lookup → account lookup (by email + tenant) → status/lockout
	 * gates → password verify → MFA gate. The returned shape tells the caller
	 * whether a second factor is still required (half-open session) or the
	 * login is complete (full session token).
	 *
	 * @param string $email The login email.
	 * @param string $password The plaintext password.
	 * @param string $tenantId The resolved tenant id.
	 * @param string|null $totpCode An optional TOTP code provided up-front.
	 * @param string $ipHash Hash of the client IP.
	 * @param string $userAgentHash Hash of the client user agent.
	 *
	 * @return array{status: string, mfaRequired: bool, accountId: string, token?: string, sessionId?: string}
	 *
	 * @throws PortalException On any authentication failure (safe, audited).
	 *   503 portalUnavailable first, for any address, when the portal cannot write.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function login(
		string $email,
		string $password,
		string $tenantId,
		?string $totpCode,
		string $ipHash,
		string $userAgentHash,
	): array {
		$this->repository->requireWritable();
		$run = new PortalLoginRun(tenantId: $tenantId, client: new PortalClient(ipHash: $ipHash, userAgentHash: $userAgentHash));
		$this->findAccount(run: $run, email: $email);
		$this->refuseClosedAccount(run: $run);
		$this->refuseLockedOut(run: $run);
		$this->verifyPassword(run: $run, password: $password);

		// Password OK from here: reset the failure counter on the in-memory
		// account so the success-path save below persists it once.
		$this->resetFailures(account: $run->account);

		if ($this->mfaRequired(account: $run->account, tenantId: $tenantId) === true) {
			return $this->completeMfaStage(run: $run, totpCode: $totpCode);
		}

		return $this->startSession(run: $run);
	}//end login()

	/**
	 * Find the account by its normalised email in the tenant and put it on the run.
	 *
	 * @param PortalLoginRun $run   The login in progress.
	 * @param string         $email The login email.
	 *
	 * @return void
	 *
	 * @throws PortalException The uniform invalid-credentials error for an unknown account.
	 */
	private function findAccount(PortalLoginRun $run, string $email): void {
		$email = strtolower(trim($email));
		$account = $this->repository->findOneBy(self::SCHEMA, ['email' => $email, 'tenantId' => $run->tenantId]);

		// Uniform failure for unknown account (no user enumeration), still audited.
		if ($account === null) {
			$this->auditUnknownAccount(run: $run);
			throw $this->invalidCredentials();
		}

		$run->account = $account;
		$run->accountId = (string)$this->repository->idOf(object: $account);
	}//end findAccount()

	/**
	 * Write the denied login-failure audit event of an unknown account.
	 *
	 * @param PortalLoginRun $run The login in progress.
	 *
	 * @return void
	 */
	private function auditUnknownAccount(PortalLoginRun $run): void {
		$this->audit->log(
			null,
			$run->tenantId,
			'login-failure',
			'denied',
			[
				'reason' => 'unknown-account',
				'ipHash' => $run->client->ipHash,
				'userAgentHash' => $run->client->userAgentHash,
			]
		);
	}//end auditUnknownAccount()

	/**
	 * Refuse a closed account (audited).
	 *
	 * @param PortalLoginRun $run The login in progress.
	 *
	 * @return void
	 *
	 * @throws PortalException 401 accountClosed.
	 */
	private function refuseClosedAccount(PortalLoginRun $run): void {
		if (($run->account['status'] ?? 'active') === 'closed') {
			$this->auditDenied(run: $run, reason: 'account-closed');
			throw new PortalException(
				status: Http::STATUS_UNAUTHORIZED,
				errorCode: 'accountClosed',
				message: 'Dit account is gesloten.'
			);
		}
	}//end refuseClosedAccount()

	/**
	 * Refuse an account inside its lockout window (audited).
	 *
	 * @param PortalLoginRun $run The login in progress.
	 *
	 * @return void
	 *
	 * @throws PortalException 429 rateLimited.
	 */
	private function refuseLockedOut(PortalLoginRun $run): void {
		if ($this->isLockedOut(account: $run->account) === true) {
			$this->auditDenied(run: $run, reason: 'locked-out');
			throw new PortalException(
				Http::STATUS_TOO_MANY_REQUESTS,
				'rateLimited',
				'Te veel mislukte pogingen. Wacht 5 minuten alstublieft.'
			);
		}
	}//end refuseLockedOut()

	/**
	 * Verify the password; a wrong one counts as a failure and is refused uniformly.
	 *
	 * @param PortalLoginRun $run      The login in progress.
	 * @param string         $password The plaintext password.
	 *
	 * @return void
	 *
	 * @throws PortalException The uniform invalid-credentials error.
	 */
	private function verifyPassword(PortalLoginRun $run, string $password): void {
		if ($this->hasher->verify($password, (string)($run->account['passwordHash'] ?? '')) === false) {
			$this->registerFailure(run: $run);
			throw $this->invalidCredentials();
		}
	}//end verifyPassword()

	/**
	 * Write a denied login-failure audit event with the given reason.
	 *
	 * @param PortalLoginRun $run    The login in progress.
	 * @param string         $reason The reason (account-closed, locked-out, invalid-mfa).
	 *
	 * @return void
	 */
	private function auditDenied(PortalLoginRun $run, string $reason): void {
		$this->audit->log(
			accountId: $run->accountId,
			tenantId: $run->tenantId,
			eventType: 'login-failure',
			outcome: 'denied',
			details: ['reason' => $reason]
		);
	}//end auditDenied()

	/**
	 * Resolve the MFA stage: either accept an up-front TOTP code and complete
	 * login, or signal that a code is still required.
	 *
	 * @param PortalLoginRun $run      The login in progress.
	 * @param string|null    $totpCode The presented code, if any.
	 *
	 * @return array<string, mixed> The login result.
	 *
	 * @throws PortalException On an invalid code.
	 */
	private function completeMfaStage(PortalLoginRun $run, ?string $totpCode): array {
		// MFA required by policy but not yet enrolled: tell the client to enroll.
		if ($this->mfaEnrolled(account: $run->account) === false) {
			return ['status' => 'mfa-enrollment-required', 'mfaRequired' => true, 'accountId' => $run->accountId];
		}

		if ($totpCode === null || $totpCode === '') {
			return ['status' => 'mfa-required', 'mfaRequired' => true, 'accountId' => $run->accountId];
		}

		if ($this->mfa->verifyCode(encryptedSecret: ($run->account['mfaSecret'] ?? null), code: $totpCode) === false) {
			$this->auditDenied(run: $run, reason: 'invalid-mfa');
			throw new PortalException(Http::STATUS_UNAUTHORIZED, 'invalidMfaCode', 'Ongeldige verificatiecode.');
		}

		return $this->startSession(run: $run);
	}//end completeMfaStage()

	/**
	 * Whether the account has MFA switched on with a stored secret.
	 *
	 * @param array<string, mixed> $account The account record.
	 *
	 * @return bool True when enrolled.
	 */
	private function mfaEnrolled(array $account): bool {
		return ($account['mfaEnabled'] ?? false) === true && ($account['mfaSecret'] ?? '') !== '';
	}//end mfaEnrolled()

	/**
	 * Mint the full session, record the login and return the authenticated result.
	 *
	 * @param PortalLoginRun $run The login in progress.
	 *
	 * @return array{status: string, mfaRequired: bool, accountId: string, token: string, sessionId: string}
	 */
	private function startSession(PortalLoginRun $run): array {
		$created = $this->sessions->createSession(
			$run->accountId,
			$run->tenantId,
			$run->client->ipHash,
			$run->client->userAgentHash,
			$this->tenant->sessionTtlHours(tenantId: $run->tenantId)
		);
		$this->recordLogin(run: $run);

		return [
			'status' => 'authenticated',
			'mfaRequired' => false,
			'accountId' => $run->accountId,
			'token' => $created['token'],
			'sessionId' => (string)$this->repository->idOf(object: $created['session']),
		];
	}//end startSession()

	/**
	 * Whether MFA must be satisfied for this account in this tenant.
	 *
	 * @param array<string, mixed> $account The account record.
	 * @param string $tenantId The tenant id.
	 *
	 * @return bool True when MFA is required.
	 */
	private function mfaRequired(array $account, string $tenantId): bool {
		if (($account['mfaEnabled'] ?? false) === true) {
			return true;
		}

		return $this->tenant->mfaEnforced(tenantId: $tenantId);
	}//end mfaRequired()

	/**
	 * Whether the account is currently in a lockout window.
	 *
	 * @param array<string, mixed> $account The account record.
	 *
	 * @return bool True when locked out.
	 */
	private function isLockedOut(array $account): bool {
		$until = ($account['lockedUntil'] ?? null);
		if ($until === null || $until === '') {
			return false;
		}

		$timestamp = strtotime((string)$until);
		return $timestamp !== false && $timestamp > $this->time->getTime();
	}//end isLockedOut()

	/**
	 * Increment the failure counter and arm a lockout once the threshold is hit.
	 *
	 * @param PortalLoginRun $run The login in progress.
	 *
	 * @return void
	 */
	private function registerFailure(PortalLoginRun $run): void {
		$account = $this->withFailureCounted(account: $run->account);
		$attempts = $account['failedLoginAttempts'];
		$this->repository->save(self::SCHEMA, $account, $run->accountId);
		$this->audit->log(
			$run->accountId,
			$run->tenantId,
			'login-failure',
			'denied',
			[
				'reason' => 'invalid-password',
				'failedAttemptCount' => $attempts,
				'ipHash' => $run->client->ipHash,
				'userAgentHash' => $run->client->userAgentHash,
			]
		);
	}//end registerFailure()

	/**
	 * The account with one more failed attempt, and a lockout armed once the threshold is hit.
	 *
	 * @param array<string, mixed> $account The account record.
	 *
	 * @return array<string, mixed> The updated account record.
	 */
	private function withFailureCounted(array $account): array {
		$attempts = ((int)($account['failedLoginAttempts'] ?? 0) + 1);
		$account['failedLoginAttempts'] = $attempts;
		if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
			$lock = $this->time->getDateTime();
			$lock->modify('+' . self::LOCKOUT_MINUTES . ' minutes');
			$account['lockedUntil'] = $lock->format(DATE_ATOM);
		}

		return $account;
	}//end withFailureCounted()

	/**
	 * Reset the failure counter and clear any lockout on the in-memory account.
	 *
	 * Mutates $account by reference (rather than persisting here) so the single
	 * success-path save in {@see self::recordLogin()} carries the reset values;
	 * persisting separately would be clobbered by that later save.
	 *
	 * @param array<string, mixed> $account The account record (by reference).
	 *
	 * @return void
	 */
	private function resetFailures(array &$account): void {
		$account['failedLoginAttempts'] = 0;
		$account['lockedUntil'] = null;
	}//end resetFailures()

	/**
	 * Stamp lastLoginAt and write the login-success audit event.
	 *
	 * @param PortalLoginRun $run The login in progress.
	 *
	 * @return void
	 */
	private function recordLogin(PortalLoginRun $run): void {
		$account = $run->account;
		$account['lastLoginAt'] = $this->time->getDateTime()->format(DATE_ATOM);
		$this->repository->save(self::SCHEMA, $account, $run->accountId);
		$this->audit->log(
			$run->accountId,
			$run->tenantId,
			'login-success',
			'success',
			[
				'ipHash' => $run->client->ipHash,
				'userAgentHash' => $run->client->userAgentHash,
			]
		);
	}//end recordLogin()

	/**
	 * The uniform invalid-credentials error (same for unknown account and bad
	 * password, to avoid user enumeration).
	 *
	 * @return PortalException The error.
	 */
	private function invalidCredentials(): PortalException {
		return new PortalException(
			Http::STATUS_UNAUTHORIZED,
			'invalidCredentials',
			'E-mailadres of wachtwoord is onjuist.'
		);
	}//end invalidCredentials()
}//end class
