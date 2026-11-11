<?php

/**
 * Pipelinq PortalAccountService.
 *
 * AVG Art. 17 account closure for portal accounts. Closure is a two-step,
 * email-confirmed action: the user requests it (a single-use token is emailed),
 * and confirming the token marks the account closed, revokes every session, and
 * audits the event. The linked contact id is deliberately retained for legal
 * (7-year tax) retention — actual pseudonymisation is deferred to the nightly
 * cleanup once retention obligations lapse (ADR-005, REQ-010).
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
 * @spec openspec/changes/customer-portal/specs.md#REQ-010
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use OCP\AppFramework\Http;
use OCP\IL10N;

/**
 * Handles portal account closure.
 *
 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
 *   sessions, tokens, delegation, documents, invoices, orders, exports and
 *   audit are all unspecified
 */
class PortalAccountService {
	/**
	 * Schema slug for accounts.
	 *
	 * @var string
	 */
	private const SCHEMA = 'crmPortalAccount';

	/**
	 * Closure-token TTL in minutes.
	 *
	 * @var int
	 */
	private const TTL_MINUTES = 60;

	/**
	 * Constructor.
	 *
	 * @param PortalObjectRepository $repository The portal object repository.
	 * @param PortalTokenService $tokens The token service.
	 * @param PortalMailService $mail The mail service.
	 * @param PortalSessionManager $sessions The session manager.
	 * @param PortalAuditService $audit The audit service.
	 * @param IL10N $l10n The localisation service.
	 */
	public function __construct(
		private PortalObjectRepository $repository,
		private PortalTokenService $tokens,
		private PortalMailService $mail,
		private PortalSessionManager $sessions,
		private PortalAuditService $audit,
		private IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Request closure: issue a confirmation token and email it.
	 *
	 * @param array<string, mixed> $account The authenticated account.
	 * @param string $tenantId The tenant id.
	 *
	 * @return void
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function requestClosure(array $account, string $tenantId): void {
		$accountId = (string)$this->repository->idOf(object: $account);
		$token = $this->tokens->issue(self::TTL_MINUTES);
		$account['closeTokenHash'] = $token['hash'];
		$account['closeExpiresAt'] = $token['expiresAt'];
		$this->repository->save(self::SCHEMA, $account, $accountId);

		$this->mail->sendTokenLink(
			(string)($account['email'] ?? ''),
			'/index.php/apps/pipelinq/portal/account-close',
			$token['plain'],
			$this->l10n->t('Confirm closing your portal account'),
			$this->l10n->t('Click the link below to confirm that you want to close your portal account. This cannot be undone.')
		);

		$this->audit->log(accountId: $accountId, tenantId: $tenantId, eventType: 'account-close', outcome: 'pending-verification');
	}//end requestClosure()

	/**
	 * Confirm closure with a valid token: mark closed and revoke all sessions.
	 *
	 * @param string $token The plaintext closure token.
	 * @param string $tenantId The tenant id.
	 *
	 * @return void
	 *
	 * @throws PortalException On an invalid/expired token.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function close(string $token, string $tenantId): void {
		$account = $this->accountForCloseToken(token: $token, tenantId: $tenantId);
		$accountId = (string)$this->repository->idOf(object: $account);
		$this->repository->save(self::SCHEMA, $this->asClosed(account: $account), $accountId);

		$this->sessions->revokeAllForAccount(accountId: $accountId, reason: 'account-closure');
		$this->mail->send(
			(string)($account['email'] ?? ''),
			$this->l10n->t('Your portal account has been closed'),
			$this->l10n->t('Your account is closed. You can no longer sign in.')
		);
		$this->audit->log(accountId: $accountId, tenantId: $tenantId, eventType: 'account-close', outcome: 'success');
	}//end close()

	/**
	 * The tenant's account the close token belongs to, when the token is
	 * valid and unexpired.
	 *
	 * @param string $token    The plaintext close token.
	 * @param string $tenantId The tenant.
	 *
	 * @return array<string, mixed> The account.
	 *
	 * @throws PortalException When no account holds the token, or it is invalid or expired.
	 */
	private function accountForCloseToken(string $token, string $tenantId): array {
		$account = $this->repository->findOneBy(
			self::SCHEMA,
			['closeTokenHash' => $this->tokens->hash(plain: $token), 'tenantId' => $tenantId]
		);
		if ($account === null || $this->closeTokenHolds(account: $account, token: $token) === false) {
			throw new PortalException(
				Http::STATUS_BAD_REQUEST,
				'invalidToken',
				'Deze bevestigings-link is ongeldig of verlopen.'
			);
		}

		return $account;
	}//end accountForCloseToken()

	/**
	 * Whether the token matches the account's stored close token and is unexpired.
	 *
	 * @param array<string, mixed> $account The account.
	 * @param string               $token   The plaintext close token.
	 *
	 * @return bool
	 */
	private function closeTokenHolds(array $account, string $token): bool {
		return $this->tokens->verify(
			plain: $token,
			storedHash: ($account['closeTokenHash'] ?? null),
			expiresAt: ($account['closeExpiresAt'] ?? null)
		);
	}//end closeTokenHolds()

	/**
	 * The account closed, with its close token cleared. The linkedContactId
	 * is RETAINED for legal retention (handled by cleanup).
	 *
	 * @param array<string, mixed> $account The account.
	 *
	 * @return array<string, mixed>
	 */
	private function asClosed(array $account): array {
		$account['status'] = 'closed';
		$account['closeTokenHash'] = null;
		$account['closeExpiresAt'] = null;

		return $account;
	}//end asClosed()
}//end class
