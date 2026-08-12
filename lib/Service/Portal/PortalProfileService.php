<?php

/**
 * Pipelinq PortalProfileService.
 *
 * Lets a portal user view and update their own profile (display name, phone,
 * address, locale, and — for B2B — job title), with every changed field landing
 * in the audit trail as {fieldName, previousValue, newValue}. Email changes are
 * never applied immediately: a verification token is emailed to the new address
 * and only a confirmed token swaps the login email, so an attacker cannot take
 * an account over by changing its email (ADR-005, REQ-007).
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
 * @spec openspec/changes/customer-portal/specs.md#REQ-007
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use OCP\IL10N;

/**
 * Portal profile read/update with audit and email verification.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Aggregates the profile-update
 *  collaborators (repository, tokens, mail, audit, l10n).
 */
class PortalProfileService {
	/**
	 * Schema slug for accounts.
	 *
	 * @var string
	 */
	private const SCHEMA = 'portalAccount';

	/**
	 * Plain editable fields (applied directly).
	 *
	 * @var array<int, string>
	 */
	private const EDITABLE = ['displayName', 'phone', 'locale', 'address', 'jobTitle'];

	/**
	 * Email-verification token TTL in minutes.
	 *
	 * @var int
	 */
	private const EMAIL_TTL_MINUTES = 30;

	/**
	 * Constructor.
	 *
	 * @param PortalObjectRepository $repository The portal object repository.
	 * @param PortalTokenService $tokens The token service.
	 * @param PortalMailService $mail The mail service.
	 * @param PortalAuditService $audit The audit service.
	 * @param IL10N $l10n The localisation service.
	 */
	public function __construct(
		private PortalObjectRepository $repository,
		private PortalTokenService $tokens,
		private PortalMailService $mail,
		private PortalAuditService $audit,
		private IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The current account's safe profile view (no secrets).
	 *
	 * @param array<string, mixed> $account The account record.
	 *
	 * @return array<string, mixed> The safe profile.
	 */
	public function present(array $account): array {
		return [
			'id' => $this->repository->idOf(object: $account),
			'email' => ($account['email'] ?? null),
			'pendingEmail' => ($account['pendingEmail'] ?? null),
			'displayName' => ($account['displayName'] ?? null),
			'phone' => ($account['phone'] ?? null),
			'address' => ($account['address'] ?? null),
			'locale' => ($account['locale'] ?? 'nl'),
			'jobTitle' => ($account['jobTitle'] ?? null),
			'accountType' => ($account['accountType'] ?? 'b2c'),
		];
	}//end present()

	/**
	 * Apply a set of profile changes, auditing each changed field, and starting
	 * an email-verification flow when the email changes.
	 *
	 * @param array<string, mixed> $account The account record.
	 * @param string $tenantId The tenant id.
	 * @param array<string, mixed> $changes The requested changes.
	 *
	 * @return array<string, mixed> The updated safe profile.
	 */
	public function update(array $account, string $tenantId, array $changes): array {
		$accountId = (string)$this->repository->idOf(object: $account);

		foreach (self::EDITABLE as $field) {
			if (array_key_exists($field, $changes) === false) {
				continue;
			}

			$new = $changes[$field];
			$old = ($account[$field] ?? null);
			if ($old === $new) {
				continue;
			}

			$account[$field] = $new;
			$this->audit->log(
				$accountId,
				$tenantId,
				'profile-update',
				'success',
				[
					'fieldName' => $field,
					'previousValue' => $old,
					'newValue' => $new,
				]
			);
		}//end foreach

		if (array_key_exists('email', $changes) === true) {
			$this->beginEmailChange(account: $account, accountId: $accountId, tenantId: $tenantId, newEmail: (string)$changes['email']);
		}

		$saved = $this->repository->save(self::SCHEMA, $account, $accountId);
		return $this->present(account: $saved);
	}//end update()

	/**
	 * Verify a pending email change with a token, swapping it into place.
	 *
	 * @param string $token The plaintext verification token.
	 * @param string $tenantId The tenant id.
	 *
	 * @return bool True when the email was verified and applied.
	 */
	public function verifyEmail(string $token, string $tenantId): bool {
		$account = $this->repository->findOneBy(
			self::SCHEMA,
			['emailVerifyTokenHash' => $this->tokens->hash(plain: $token), 'tenantId' => $tenantId]
		);
		if ($account === null) {
			return false;
		}

		if ($this->tokens->verify(
			$token,
			($account['emailVerifyTokenHash'] ?? null),
			($account['emailVerifyExpiresAt'] ?? null)
		) === false
		) {
			return false;
		}

		$accountId = (string)$this->repository->idOf(object: $account);
		$newEmail = strtolower((string)($account['pendingEmail'] ?? ''));
		$account['email'] = $newEmail;
		$account['pendingEmail'] = null;
		$account['emailVerifyTokenHash'] = null;
		$account['emailVerifyExpiresAt'] = null;
		$this->repository->save(self::SCHEMA, $account, $accountId);

		$this->audit->log(
			accountId: $accountId,
			tenantId: $tenantId,
			eventType: 'email-change',
			outcome: 'success',
			details: ['newValue' => $newEmail]
		);
		return true;
	}//end verifyEmail()

	/**
	 * Stage an email change: store the pending email + token and send the link.
	 * The login email is left untouched until verification.
	 *
	 * @param array<string, mixed> $account The account record (by reference via return).
	 * @param string $accountId The account id.
	 * @param string $tenantId The tenant id.
	 * @param string $newEmail The requested new email.
	 *
	 * @return void
	 */
	private function beginEmailChange(array &$account, string $accountId, string $tenantId, string $newEmail): void {
		$newEmail = strtolower(trim($newEmail));
		if ($newEmail === '' || $newEmail === strtolower((string)($account['email'] ?? ''))) {
			return;
		}

		$token = $this->tokens->issue(self::EMAIL_TTL_MINUTES);
		$account['pendingEmail'] = $newEmail;
		$account['emailVerifyTokenHash'] = $token['hash'];
		$account['emailVerifyExpiresAt'] = $token['expiresAt'];

		$this->mail->sendTokenLink(
			$newEmail,
			'/index.php/apps/pipelinq/portal/verify-email',
			$token['plain'],
			$this->l10n->t('Confirm your new email address'),
			$this->l10n->t('Click the link below to confirm this email address for your portal account.')
		);

		$this->audit->log(
			$accountId,
			$tenantId,
			'profile-update',
			'pending-verification',
			[
				'fieldName' => 'email',
				'newValue' => $newEmail,
			]
		);
	}//end beginEmailChange()
}//end class
