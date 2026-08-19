<?php

/**
 * Pipelinq PortalScopeResolver.
 *
 * Computes, for an authenticated portal account and a delegation scope, the set
 * of contact/client ids whose business data the account may read: always its
 * own linked contact/organisation, plus the linked contact/organisation of any
 * grantor who has delegated that exact scope and whose grant is still valid.
 * Every read facade filters strictly against this set, so a portal user can only
 * ever see their own data and explicitly delegated data — the per-customer IDOR
 * boundary (ADR-005, REQ-003).
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

/**
 * Resolves the per-account, per-scope read boundary.
 */
class PortalScopeResolver
{
    /**
     * Schema slug for accounts.
     *
     * @var string
     */
    private const ACCOUNT_SCHEMA = 'portalAccount';

    /**
     * Constructor.
     *
     * @param PortalObjectRepository  $repository  The portal object repository.
     * @param PortalDelegationService $delegations The delegation service.
     */
    public function __construct(
        private PortalObjectRepository $repository,
        private PortalDelegationService $delegations,
    ) {
    }//end __construct()

    /**
     * Resolve the readable scope for an account and a delegation scope.
     *
     * Returns a structure listing the account's own contact/client ids plus,
     * per grantor, the delegated contact/client ids — each tagged with the
     * grantor account id so a facade can mark results `delegatedFrom`.
     *
     * @param array<string, mixed> $account The authenticated account record.
     * @param string               $scope   The delegation scope (e.g. view-invoices).
     *
     * @return array{
     *   ownContactIds: array<int, string>,
     *   ownClientIds: array<int, string>,
     *   delegated: array<int, array{from: string, contactIds: array<int, string>, clientIds: array<int, string>}>
     * }
     */
    public function resolve(array $account, string $scope): array
    {
        $ownContactIds = $this->idList(value: ($account['linkedContactId'] ?? null));
        $ownClientIds  = $this->idList(value: ($account['linkedOrganisationId'] ?? null));

        $delegated = [];
        $accountId = (string) $this->repository->idOf(object: $account);
        foreach ($this->delegations->getActiveScopes(granteeAccountId: $accountId) as $grant) {
            if (in_array($scope, $grant['scopes'], true) === false) {
                continue;
            }

            $grantor = $this->repository->find(self::ACCOUNT_SCHEMA, $grant['grantorAccountId']);
            if ($grantor === null) {
                continue;
            }

            $delegated[] = [
                'from'       => $grant['grantorAccountId'],
                'contactIds' => $this->idList(value: ($grantor['linkedContactId'] ?? null)),
                'clientIds'  => $this->idList(value: ($grantor['linkedOrganisationId'] ?? null)),
            ];
        }

        return [
            'ownContactIds' => $ownContactIds,
            'ownClientIds'  => $ownClientIds,
            'delegated'     => $delegated,
        ];
    }//end resolve()

    /**
     * Determine the `delegatedFrom` tag for an object whose owning contact /
     * client id is known, given a resolved scope.
     *
     * Own data returns null; delegated data returns the grantor account id; an
     * object that matches neither returns false (caller must drop it).
     *
     * @param array<string, mixed> $resolved  The resolved scope from resolve().
     * @param string|null          $contactId The object's owning contact id.
     * @param string|null          $clientId  The object's owning client id.
     *
     * @return string|null|false null=own, string=delegatedFrom, false=not visible.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) The own-vs-delegated-vs-not
     *  decision compares the object's contact/client against each scope bucket;
     *  the checks are flat and load-bearing for the IDOR boundary.
     */
    public function classify(array $resolved, ?string $contactId, ?string $clientId): string|null|false
    {
        if (($contactId !== null && in_array($contactId, $resolved['ownContactIds'], true) === true)
            || ($clientId !== null && in_array($clientId, $resolved['ownClientIds'], true) === true)
        ) {
            return null;
        }

        foreach ($resolved['delegated'] as $entry) {
            if (($contactId !== null && in_array($contactId, $entry['contactIds'], true) === true)
                || ($clientId !== null && in_array($clientId, $entry['clientIds'], true) === true)
            ) {
                return $entry['from'];
            }
        }

        return false;
    }//end classify()

    /**
     * Normalise a possibly-null id into a list of non-empty id strings.
     *
     * @param mixed $value The raw id value.
     *
     * @return array<int, string> The id list.
     */
    private function idList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value) === true) {
            return array_values(array_filter(array_map('strval', $value), static fn ($id): bool => $id !== ''));
        }

        return [(string) $value];
    }//end idList()
}//end class
