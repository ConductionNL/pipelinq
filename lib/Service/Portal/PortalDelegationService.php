<?php

/**
 * Pipelinq PortalDelegationService.
 *
 * B2B delegation: a portal account may grant a colleague a subset of scopes
 * (view-invoices, view-contracts, submit-requests) over its organisation data,
 * time-bounded and revocable. Grants are created only by the granter (the
 * granterAccountId is taken from the authenticated session, never the request
 * body), grantees cannot re-delegate, and a read facade only widens a grantee's
 * visible data for scopes that are currently valid (ADR-005, REQ-003).
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
 * @spec openspec/changes/customer-portal/specs.md#REQ-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Manages B2B portal delegations.
 *
 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
 *   sessions, tokens, delegation, documents, invoices, orders, exports and
 *   audit are all unspecified
 */
class PortalDelegationService {
	/**
	 * Schema slug for delegations.
	 *
	 * @var string
	 */
	private const SCHEMA = 'portalDelegation';

	/**
	 * Schema slug for accounts.
	 *
	 * @var string
	 */
	private const ACCOUNT_SCHEMA = 'crmPortalAccount';

	/**
	 * The scopes a delegation may carry.
	 *
	 * @var array<int, string>
	 */
	public const VALID_SCOPES = ['view-invoices', 'view-contracts', 'submit-requests'];

	/**
	 * Constructor.
	 *
	 * @param PortalObjectRepository $repository The portal object repository.
	 * @param PortalAuditService $audit The audit service.
	 * @param ITimeFactory $time The time factory.
	 */
	public function __construct(
		private PortalObjectRepository $repository,
		private PortalAuditService $audit,
		private ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * Grant a delegation from an authenticated granter to a colleague email.
	 *
	 * @param string $granterAccountId The authenticated granter (from session).
	 * @param string $tenantId The tenant id.
	 * @param string $granteeEmail The colleague's email.
	 * @param array<int, string> $scopes The requested scopes.
	 * @param string|null $validUntil Optional ISO-8601 expiry.
	 *
	 * @return array<string, mixed> The created delegation.
	 *
	 * @throws PortalException On invalid scopes or a self-grant.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function grant(
		string $granterAccountId,
		string $tenantId,
		string $granteeEmail,
		array $scopes,
		?string $validUntil,
	): array {
		$granteeEmail = strtolower(trim($granteeEmail));
		$scopes = $this->sanitiseScopes(scopes: $scopes);

		$this->refuseSelfGrant(granterAccountId: $granterAccountId, granteeEmail: $granteeEmail);
		$granteeId = $this->granteeIdOf(granteeEmail: $granteeEmail, tenantId: $tenantId);

		$record = [
			'granterAccountId' => $granterAccountId,
			'granteeAccountId' => $granteeId,
			'granteeEmail' => $granteeEmail,
			'tenantId' => $tenantId,
			'scopes' => $scopes,
			'validFrom' => $this->time->getDateTime()->format(DATE_ATOM),
			'validUntil' => $validUntil,
			'revokedAt' => null,
		];
		$delegation = $this->repository->save(self::SCHEMA, $record);
		$this->auditGrant(record: $record, delegationId: $this->repository->idOf(object: $delegation));

		return $delegation;
	}//end grant()

	/**
	 * Audit a stored grant against its granter and tenant.
	 *
	 * @param array<string, mixed> $record       The delegation as it was stored.
	 * @param string|null          $delegationId The stored delegation's id.
	 *
	 * @return void
	 */
	private function auditGrant(array $record, ?string $delegationId): void {
		$this->audit->log(
			$record['granterAccountId'],
			$record['tenantId'],
			'delegation-grant',
			'success',
			[
				'targetObjectType' => 'portalDelegation',
				'targetObjectId' => $delegationId,
				'granteeEmail' => $record['granteeEmail'],
				'scopes' => $record['scopes'],
			]
		);
	}//end auditGrant()

	/**
	 * Refuse a grant to the granter's own email.
	 *
	 * @param string $granterAccountId The authenticated granter.
	 * @param string $granteeEmail     The normalised colleague email.
	 *
	 * @return void
	 *
	 * @throws PortalException When the granter delegates to themself.
	 */
	private function refuseSelfGrant(string $granterAccountId, string $granteeEmail): void {
		$granter = $this->repository->find(self::ACCOUNT_SCHEMA, $granterAccountId);
		if ($granter !== null && strtolower((string)($granter['email'] ?? '')) === $granteeEmail) {
			throw new PortalException(
				Http::STATUS_UNPROCESSABLE_ENTITY,
				'cannotDelegateToSelf',
				'U kunt geen toegang aan uzelf verlenen.'
			);
		}
	}//end refuseSelfGrant()

	/**
	 * The id of the tenant's portal account with the colleague email, or null when there is none yet.
	 *
	 * @param string $granteeEmail The normalised colleague email.
	 * @param string $tenantId     The tenant id.
	 *
	 * @return string|null
	 */
	private function granteeIdOf(string $granteeEmail, string $tenantId): ?string {
		$grantee = $this->repository->findOneBy(
			self::ACCOUNT_SCHEMA,
			['email' => $granteeEmail, 'tenantId' => $tenantId]
		);
		if ($grantee === null) {
			return null;
		}

		return $this->repository->idOf(object: $grantee);
	}//end granteeIdOf()

	/**
	 * Revoke a delegation owned by the authenticated granter.
	 *
	 * The granter id is verified against the stored delegation so a user can
	 * never revoke another account's grant.
	 *
	 * @param string $delegationId The delegation id.
	 * @param string $granterAccountId The authenticated granter.
	 * @param string $tenantId The tenant id.
	 *
	 * @return void
	 *
	 * @throws PortalException When the delegation is not the granter's.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function revoke(string $delegationId, string $granterAccountId, string $tenantId): void {
		$delegation = $this->repository->find(self::SCHEMA, $delegationId);
		if ($delegation === null
			|| ($delegation['granterAccountId'] ?? null) !== $granterAccountId
			|| ($delegation['tenantId'] ?? null) !== $tenantId
		) {
			// 404, not 403: do not reveal the existence of another's delegation.
			throw new PortalException(Http::STATUS_NOT_FOUND, 'notFound', 'Niet gevonden.');
		}

		$delegation['revokedAt'] = $this->time->getDateTime()->format(DATE_ATOM);
		$this->repository->save(self::SCHEMA, $delegation, $delegationId);
		$this->audit->log(
			$granterAccountId,
			$tenantId,
			'delegation-revoke',
			'success',
			[
				'targetObjectType' => 'portalDelegation',
				'targetObjectId' => $delegationId,
			]
		);
	}//end revoke()

	/**
	 * List delegations granted by an account (its own grants only).
	 *
	 * @param string $granterAccountId The granter id.
	 *
	 * @return array<int, array<string, mixed>> The delegations.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function listGrantedBy(string $granterAccountId): array {
		return $this->repository->findAll(self::SCHEMA, ['granterAccountId' => $granterAccountId]);
	}//end listGrantedBy()

	/**
	 * The active (valid, unrevoked) delegations a grantee currently holds,
	 * each as {grantorAccountId, scopes[]}.
	 *
	 * @param string $granteeAccountId The grantee id.
	 *
	 * @return array<int, array{grantorAccountId: string, scopes: array<int, string>}>
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function getActiveScopes(string $granteeAccountId): array {
		$delegations = $this->repository->findAll(self::SCHEMA, ['granteeAccountId' => $granteeAccountId]);
		$active = [];
		foreach ($delegations as $delegation) {
			if ($this->isActive(delegation: $delegation) === false) {
				continue;
			}

			$active[] = [
				'grantorAccountId' => (string)($delegation['granterAccountId'] ?? ''),
				'scopes' => $this->sanitiseScopes(scopes: ($delegation['scopes'] ?? [])),
			];
		}

		return $active;
	}//end getActiveScopes()

	/**
	 * Whether a delegation is currently active (not revoked, within its window).
	 *
	 * @param array<string, mixed> $delegation The delegation.
	 *
	 * @return bool True when active.
	 */
	private function isActive(array $delegation): bool {
		$revokedAt = ($delegation['revokedAt'] ?? null);
		if ($revokedAt !== null && $revokedAt !== '') {
			return false;
		}

		$now = $this->time->getTime();

		$from = ($delegation['validFrom'] ?? null);
		if ($from !== null && $from !== '' && strtotime((string)$from) > $now) {
			return false;
		}

		$until = ($delegation['validUntil'] ?? null);
		if ($until !== null && $until !== '' && strtotime((string)$until) < $now) {
			return false;
		}

		return true;
	}//end isActive()

	/**
	 * Keep only recognised scope values, de-duplicated.
	 *
	 * @param mixed $scopes The raw scopes.
	 *
	 * @return array<int, string> The valid scopes.
	 */
	private function sanitiseScopes(mixed $scopes): array {
		if (is_array($scopes) === false) {
			return [];
		}

		$clean = [];
		foreach ($scopes as $scope) {
			if (is_string($scope) === true && in_array($scope, self::VALID_SCOPES, true) === true) {
				$clean[$scope] = $scope;
			}
		}

		return array_values($clean);
	}//end sanitiseScopes()
}//end class
