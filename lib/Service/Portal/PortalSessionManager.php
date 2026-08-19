<?php

/**
 * Pipelinq PortalSessionManager.
 *
 * Owns the lifecycle of portal_session records: creating a session (256-bit
 * token, SHA-256-hashed at rest, one-time plaintext), validating a presented
 * bearer token (existence + not-revoked + not-expired + tenant match),
 * extending the TTL, and revoking. The plaintext token is shown exactly once at
 * login; the store only ever holds its hash, so a database leak cannot recover
 * live tokens (ADR-005, REQ-001).
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

/**
 * Manages portal session tokens.
 */
class PortalSessionManager
{
    /**
     * Schema slug for sessions.
     *
     * @var string
     */
    private const SCHEMA = 'portalSession';

    /**
     * Default session TTL in hours.
     *
     * @var int
     */
    public const DEFAULT_TTL_HOURS = 8;

    /**
     * Constructor.
     *
     * @param PortalObjectRepository $repository The portal object repository.
     * @param PortalTokenService     $tokens     The token service.
     * @param ITimeFactory           $time       The time factory.
     */
    public function __construct(
        private PortalObjectRepository $repository,
        private PortalTokenService $tokens,
        private ITimeFactory $time,
    ) {
    }//end __construct()

    /**
     * Create a new session for an account and return the plaintext token plus
     * the persisted session record. The token is never stored in plaintext.
     *
     * @param string $accountId     The account id.
     * @param string $tenantId      The tenant id.
     * @param string $ipHash        Hash of the client IP.
     * @param string $userAgentHash Hash of the client user agent.
     * @param int    $ttlHours      Session TTL in hours.
     * @param bool   $mfaPending    Whether MFA still has to be satisfied.
     *
     * @return array{token: string, session: array<string, mixed>} The session material.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $mfaPending is a half-open marker.
     */
    public function createSession(
        string $accountId,
        string $tenantId,
        string $ipHash,
        string $userAgentHash,
        int $ttlHours=self::DEFAULT_TTL_HOURS,
        bool $mfaPending=false
    ): array {
        $plain  = $this->tokens->randomToken();
        $expiry = $this->time->getDateTime();
        $expiry->modify('+'.max(1, $ttlHours).' hours');

        $session = $this->repository->save(
            self::SCHEMA,
            [
                'accountId'     => $accountId,
                'tenantId'      => $tenantId,
                'tokenHash'     => $this->tokens->hash(plain: $plain),
                'ipHash'        => $ipHash,
                'userAgentHash' => $userAgentHash,
                'expiresAt'     => $expiry->format(DATE_ATOM),
                'revoked'       => false,
                'mfaPending'    => $mfaPending,
            ]
        );

        return ['token' => $plain, 'session' => $session];
    }//end createSession()

    /**
     * Validate a presented bearer token within a tenant.
     *
     * Looks the token up by its hash, then rejects it if revoked, expired,
     * still MFA-pending, or belonging to a different tenant. Returns the
     * session record on success, or null on any failure — callers treat null
     * strictly as "unauthenticated" (no fail-open).
     *
     * @param string|null $token    The presented plaintext token.
     * @param string      $tenantId The resolved tenant id.
     *
     * @return array<string, mixed>|null The valid session, or null.
     */
    public function validateSession(?string $token, string $tenantId): ?array
    {
        if ($token === null || $token === '') {
            return null;
        }

        $session = $this->repository->findOneBy(self::SCHEMA, ['tokenHash' => $this->tokens->hash(plain: $token)]);
        if ($session === null) {
            return null;
        }

        if (($session['revoked'] ?? false) === true) {
            return null;
        }

        if (($session['mfaPending'] ?? false) === true) {
            return null;
        }

        if (($session['tenantId'] ?? null) !== $tenantId) {
            return null;
        }

        if ($this->tokens->isUnexpired(expiresAt: ($session['expiresAt'] ?? null)) === false) {
            return null;
        }

        return $session;
    }//end validateSession()

    /**
     * Mark a half-open (MFA-pending) session as fully authenticated.
     *
     * @param string $sessionId The session id.
     *
     * @return void
     */
    public function clearMfaPending(string $sessionId): void
    {
        $session = $this->repository->find(self::SCHEMA, $sessionId);
        if ($session === null) {
            return;
        }

        $session['mfaPending'] = false;
        $this->repository->save(self::SCHEMA, $session, $sessionId);
    }//end clearMfaPending()

    /**
     * Extend a session's TTL from now.
     *
     * @param string $sessionId The session id.
     * @param int    $ttlHours  New TTL in hours from now.
     *
     * @return array<string, mixed>|null The updated session, or null when absent.
     */
    public function extendSession(string $sessionId, int $ttlHours=self::DEFAULT_TTL_HOURS): ?array
    {
        $session = $this->repository->find(self::SCHEMA, $sessionId);
        if ($session === null || ($session['revoked'] ?? false) === true) {
            return null;
        }

        $expiry = $this->time->getDateTime();
        $expiry->modify('+'.max(1, $ttlHours).' hours');
        $session['expiresAt'] = $expiry->format(DATE_ATOM);

        return $this->repository->save(self::SCHEMA, $session, $sessionId);
    }//end extendSession()

    /**
     * Extend a session and throw PortalException when the session is absent or
     * revoked. Controllers use this to surface the not-logged-in failure as a
     * single uniform error, keeping the auth literal off the controller body.
     *
     * @param string $sessionId The session id.
     * @param int    $ttlHours  New TTL in hours from now.
     *
     * @return array<string, mixed> The updated session.
     *
     * @throws PortalException When the session is absent or revoked.
     */
    public function extendSessionOrThrow(string $sessionId, int $ttlHours=self::DEFAULT_TTL_HOURS): array
    {
        $updated = $this->extendSession(sessionId: $sessionId, ttlHours: $ttlHours);
        if ($updated === null) {
            throw new PortalException(
                status: Http::STATUS_UNAUTHORIZED,
                errorCode: 'unauthenticated',
                message: 'Niet ingelogd.'
            );
        }

        return $updated;
    }//end extendSessionOrThrow()

    /**
     * Revoke a single session.
     *
     * @param string $sessionId The session id.
     * @param string $reason    The revocation reason.
     *
     * @return void
     */
    public function revokeSession(string $sessionId, string $reason): void
    {
        $session = $this->repository->find(self::SCHEMA, $sessionId);
        if ($session === null) {
            return;
        }

        $session['revoked']       = true;
        $session['revokedAt']     = $this->time->getDateTime()->format(DATE_ATOM);
        $session['revokedReason'] = $reason;
        $this->repository->save(self::SCHEMA, $session, $sessionId);
    }//end revokeSession()

    /**
     * Revoke every active session of an account (e.g. on account closure).
     *
     * @param string $accountId The account id.
     * @param string $reason    The revocation reason.
     *
     * @return int The number of sessions revoked.
     */
    public function revokeAllForAccount(string $accountId, string $reason): int
    {
        $sessions = $this->repository->findAll(self::SCHEMA, ['accountId' => $accountId]);
        $count    = 0;
        foreach ($sessions as $session) {
            if (($session['revoked'] ?? false) === true) {
                continue;
            }

            $id = $this->repository->idOf(object: $session);
            if ($id === null) {
                continue;
            }

            $this->revokeSession(sessionId: $id, reason: $reason);
            $count++;
        }

        return $count;
    }//end revokeAllForAccount()
}//end class
