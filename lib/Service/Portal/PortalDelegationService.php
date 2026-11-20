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
 * @spec openspec/changes/archive/2026-06-14-customer-portal/specs.md#REQ-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use OCA\Pipelinq\Kernel\Contracts\PortalDelegationServiceInterface;
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
class PortalDelegationService implements PortalDelegationServiceInterface {
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
		$request = [
			'granterAccountId' => $granterAccountId,
			'tenantId' => $tenantId,
			'granteeEmail' => $granteeEmail,
			'scopes' => $scopes,
			'validUntil' => $validUntil,
		];

		return $this->storeGrant(request: $request);
	}//end grant()

	/**
	 * Validate a grant request, store the delegation and audit it.
	 *
	 * @param array<string, mixed> $request The grant as asked: granterAccountId, tenantId, granteeEmail, scopes, validUntil.
	 *
	 * @return array<string, mixed> The created delegation.
	 *
	 * @throws PortalException On invalid scopes or a self-grant.
	 */
	private function storeGrant(array $request): array {
		$granterAccountId = $request['granterAccountId'];
		$tenantId = $request['tenantId'];
		$granteeEmail = strtolower(trim($request['granteeEmail']));
		$scopes = $this->sanitiseScopes(scopes: $request['scopes']);

		$this->refuseSelfGrant(granterAccountId: $granterAccountId, granteeEmail: $granteeEmail);
		$granteeId = $this->granteeIdOf(granteeEmail: $granteeEmail, tenantId: $tenantId);

		$record = [
			'granterAccountId' => $granterAccountId,
			'granteeAccountId' => $granteeId,
			'granteeEmail' => $granteeEmail,
			'tenantId' => $tenantId,
			'scopes' => $scopes,
			'validFrom' => $this->time->getDateTime()->format(DATE_ATOM),
			'validUntil' => $request['validUntil'],
			'revokedAt' => null,
		];
		$delegation = $this->repository->save(self::SCHEMA, $record);
		$this->auditGrant(record: $record, delegationId: $this->repository->idOf(object: $delegation));

		return $delegation;
	}//end storeGrant()

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
		$owner = ['granterAccountId' => $granterAccountId, 'tenantId' => $tenantId];
		$delegation = $this->ownedDelegation(delegationId: $delegationId, owner: $owner);

		$delegation['revokedAt'] = $this->time->getDateTime()->format(DATE_ATOM);
		$this->repository->save(self::SCHEMA, $delegation, $delegationId);
		$this->auditRevoke(delegationId: $delegationId, owner: $owner);
	}//end revoke()

	/**
	 * The stored delegation when the granter and tenant own it.
	 *
	 * @param string                                          $delegationId The delegation id.
	 * @param array{granterAccountId: string, tenantId: string} $owner        The authenticated granter and tenant.
	 *
	 * @return array<string, mixed> The delegation.
	 *
	 * @throws PortalException 404 when it does not exist or is not theirs.
	 */
	private function ownedDelegation(string $delegationId, array $owner): array {
		$delegation = $this->repository->find(self::SCHEMA, $delegationId);
		if ($delegation === null
			|| ($delegation['granterAccountId'] ?? null) !== $owner['granterAccountId']
			|| ($delegation['tenantId'] ?? null) !== $owner['tenantId']
		) {
			// 404, not 403: do not reveal the existence of another's delegation.
			throw new PortalException(Http::STATUS_NOT_FOUND, 'notFound', 'Niet gevonden.');
		}

		return $delegation;
	}//end ownedDelegation()

	/**
	 * Audit a revoked delegation against its granter and tenant.
	 *
	 * @param string                                          $delegationId The delegation id.
	 * @param array{granterAccountId: string, tenantId: string} $owner        The granter and tenant.
	 *
	 * @return void
	 */
	private function auditRevoke(string $delegationId, array $owner): void {
		$this->audit->log(
			$owner['granterAccountId'],
			$owner['tenantId'],
			'delegation-revoke',
			'success',
			[
				'targetObjectType' => 'portalDelegation',
				'targetObjectId' => $delegationId,
			]
		);
	}//end auditRevoke()

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
