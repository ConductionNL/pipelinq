<?php

/**
 * Pipelinq PasswordResetService.
 *
 * Implements the forgotten-password flow for portal accounts: issue a
 * single-use, 30-minute, hashed reset token and email a link, then accept the
 * token to set a new argon2id password and revoke all live sessions. The
 * request step never reveals whether an email exists (anti-enumeration), and a
 * used or expired token is rejected (ADR-005, REQ-001 / REQ-007).
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
use OCP\IL10N;
use OCP\Security\IHasher;

/**
 * Handles portal password resets.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Aggregates the reset-flow
 *  collaborators (repository, tokens, hasher, mailer, sessions, audit, l10n).
 *
 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
 *   sessions, tokens, delegation, documents, invoices, orders, exports and
 *   audit are all unspecified
 */
class PasswordResetService {
	/**
	 * Schema slug for accounts.
	 *
	 * @var string
	 */
	private const SCHEMA = 'crmPortalAccount';

	/**
	 * Reset-token TTL in minutes.
	 *
	 * @var int
	 */
	private const TTL_MINUTES = 30;

	/**
	 * Minimum new-password length (mirrors the Nextcloud default policy).
	 *
	 * @var int
	 */
	private const MIN_PASSWORD_LENGTH = 10;

	/**
	 * Constructor.
	 *
	 * @param PortalObjectRepository $repository The portal object repository.
	 * @param PortalTokenService $tokens The token service.
	 * @param IHasher $hasher The password hasher.
	 * @param PortalMailService $mail The mail service.
	 * @param PortalSessionManager $sessions The session manager.
	 * @param PortalAuditService $audit The audit service.
	 * @param IL10N $l10n The localisation service.
	 */
	public function __construct(
		private PortalObjectRepository $repository,
		private PortalTokenService $tokens,
		private IHasher $hasher,
		private PortalMailService $mail,
		private PortalSessionManager $sessions,
		private PortalAuditService $audit,
		private IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Begin a reset: if the email maps to an active account, issue a token and
	 * email a link. Always returns silently so the caller can respond with a
	 * uniform "if the address exists, you'll get an email" message.
	 *
	 * @param string $email The account email.
	 * @param string $tenantId The tenant id.
	 *
	 * @return void
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function requestReset(string $email, string $tenantId): void {
		$email = strtolower(trim($email));
		$account = $this->repository->findOneBy(self::SCHEMA, ['email' => $email, 'tenantId' => $tenantId]);
		if ($account === null || ($account['status'] ?? 'active') === 'closed') {
			return;
		}

		$token = $this->tokens->issue(self::TTL_MINUTES);
		$account['passwordResetTokenHash'] = $token['hash'];
		$account['passwordResetExpiresAt'] = $token['expiresAt'];
		$this->repository->save(self::SCHEMA, $account, $this->repository->idOf(object: $account));

		$this->mail->sendTokenLink(
			$email,
			'/index.php/apps/pipelinq/portal/password-reset',
			$token['plain'],
			$this->l10n->t('Reset your portal password'),
			$this->l10n->t('Click the link below to choose a new password. This link expires in 30 minutes.')
		);
	}//end requestReset()

	/**
	 * Complete a reset with a valid token and a policy-compliant new password.
	 *
	 * @param string $token The plaintext reset token.
	 * @param string $newPassword The new plaintext password.
	 * @param string $tenantId The tenant id.
	 *
	 * @return void
	 *
	 * @throws PortalException On an invalid/expired token or a weak password.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function resetPassword(string $token, string $newPassword, string $tenantId): void {
		if (strlen($newPassword) < self::MIN_PASSWORD_LENGTH) {
			throw new PortalException(
				Http::STATUS_UNPROCESSABLE_ENTITY,
				'weakPassword',
				'Wachtwoord moet minimaal 10 tekens bevatten.'
			);
		}

		$account = $this->findByResetToken(token: $token, tenantId: $tenantId);
		if ($account === null) {
			throw new PortalException(
				Http::STATUS_BAD_REQUEST,
				'invalidToken',
				'Deze herstel-link is ongeldig of verlopen.'
			);
		}

		$accountId = (string)$this->repository->idOf(object: $account);
		$account['passwordHash'] = $this->hasher->hash($newPassword);
		$account['passwordResetTokenHash'] = null;
		$account['passwordResetExpiresAt'] = null;
		$account['failedLoginAttempts'] = 0;
		$account['lockedUntil'] = null;
		$this->repository->save(self::SCHEMA, $account, $accountId);

		// A password reset invalidates every existing session.
		$this->sessions->revokeAllForAccount(accountId: $accountId, reason: 'password-reset');
		$this->audit->log(accountId: $accountId, tenantId: $tenantId, eventType: 'password-reset', outcome: 'success');
	}//end resetPassword()

	/**
	 * Find the account whose stored reset-token hash matches and is unexpired.
	 *
	 * @param string $token The plaintext token.
	 * @param string $tenantId The tenant id.
	 *
	 * @return array<string, mixed>|null The account, or null.
	 */
	private function findByResetToken(string $token, string $tenantId): ?array {
		$candidate = $this->repository->findOneBy(
			self::SCHEMA,
			['passwordResetTokenHash' => $this->tokens->hash(plain: $token), 'tenantId' => $tenantId]
		);
		if ($candidate === null) {
			return null;
		}

		if ($this->tokens->verify(
			$token,
			($candidate['passwordResetTokenHash'] ?? null),
			($candidate['passwordResetExpiresAt'] ?? null)
		) === false
		) {
			return null;
		}

		return $candidate;
	}//end findByResetToken()
}//end class
