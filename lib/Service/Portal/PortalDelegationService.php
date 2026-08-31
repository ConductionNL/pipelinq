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
	private const ACCOUNT_SCHEMA = 'portalAccount';

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

		$granter = $this->repository->find(self::ACCOUNT_SCHEMA, $granterAccountId);
		if ($granter !== null && strtolower((string)($granter['email'] ?? '')) === $granteeEmail) {
			throw new PortalException(
				Http::STATUS_UNPROCESSABLE_ENTITY,
				'cannotDelegateToSelf',
				'U kunt geen toegang aan uzelf verlenen.'
			);
		}

		$grantee = $this->repository->findOneBy(
			self::ACCOUNT_SCHEMA,
			['email' => $granteeEmail, 'tenantId' => $tenantId]
		);
		$granteeId = null;
		if ($grantee !== null) {
			$granteeId = $this->repository->idOf(object: $grantee);
		}

		$delegation = $this->repository->save(
			self::SCHEMA,
			[
				'granterAccountId' => $granterAccountId,
				'granteeAccountId' => $granteeId,
				'granteeEmail' => $granteeEmail,
				'tenantId' => $tenantId,
				'scopes' => $scopes,
				'validFrom' => $this->time->getDateTime()->format(DATE_ATOM),
				'validUntil' => $validUntil,
				'revokedAt' => null,
			]
		);

		$this->audit->log(
			$granterAccountId,
			$tenantId,
			'delegation-grant',
			'success',
			[
				'targetObjectType' => 'portalDelegation',
				'targetObjectId' => $this->repository->idOf(object: $delegation),
				'granteeEmail' => $granteeEmail,
				'scopes' => $scopes,
			]
		);

		return $delegation;
	}//end grant()

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
