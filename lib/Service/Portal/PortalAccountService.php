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
 */
class PortalAccountService
{
    /**
     * Schema slug for accounts.
     *
     * @var string
     */
    private const SCHEMA = 'portalAccount';

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
     * @param PortalTokenService     $tokens     The token service.
     * @param PortalMailService      $mail       The mail service.
     * @param PortalSessionManager   $sessions   The session manager.
     * @param PortalAuditService     $audit      The audit service.
     * @param IL10N                  $l10n       The localisation service.
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
     * @param array<string, mixed> $account  The authenticated account.
     * @param string               $tenantId The tenant id.
     *
     * @return void
     */
    public function requestClosure(array $account, string $tenantId): void
    {
        $accountId = (string) $this->repository->idOf(object: $account);
        $token     = $this->tokens->issue(self::TTL_MINUTES);
        $account['closeTokenHash'] = $token['hash'];
        $account['closeExpiresAt'] = $token['expiresAt'];
        $this->repository->save(self::SCHEMA, $account, $accountId);

        $this->mail->sendTokenLink(
            (string) ($account['email'] ?? ''),
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
     * @param string $token    The plaintext closure token.
     * @param string $tenantId The tenant id.
     *
     * @return void
     *
     * @throws PortalException On an invalid/expired token.
     */
    public function close(string $token, string $tenantId): void
    {
        $account    = $this->repository->findOneBy(
            self::SCHEMA,
            ['closeTokenHash' => $this->tokens->hash(plain: $token), 'tenantId' => $tenantId]
        );
        $tokenValid = false;
        if ($account !== null) {
            $tokenValid = $this->tokens->verify(
                plain: $token,
                storedHash: ($account['closeTokenHash'] ?? null),
                expiresAt: ($account['closeExpiresAt'] ?? null)
            );
        }

        if ($account === null || $tokenValid === false) {
            throw new PortalException(
                Http::STATUS_BAD_REQUEST,
                'invalidToken',
                'Deze bevestigings-link is ongeldig of verlopen.'
            );
        }

        $accountId         = (string) $this->repository->idOf(object: $account);
        $account['status'] = 'closed';
        $account['closeTokenHash'] = null;
        $account['closeExpiresAt'] = null;
        // The linkedContactId is RETAINED for legal retention (handled by cleanup).
        $this->repository->save(self::SCHEMA, $account, $accountId);

        $this->sessions->revokeAllForAccount(accountId: $accountId, reason: 'account-closure');
        $this->mail->send(
            (string) ($account['email'] ?? ''),
            $this->l10n->t('Your portal account has been closed'),
            $this->l10n->t('Your account is closed. You can no longer sign in.')
        );
        $this->audit->log(accountId: $accountId, tenantId: $tenantId, eventType: 'account-close', outcome: 'success');
    }//end close()
}//end class
