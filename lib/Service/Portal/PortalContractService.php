<?php

/**
 * Pipelinq PortalContractService.
 *
 * Read facade exposing a customer's contracts in the portal. Contracts are read
 * from an optional `contract` schema in the main register (degrading to an empty
 * list on instances that do not model contracts), filtered server-side to own +
 * `view-contracts`-delegated data. Presents the safe contract summary fields
 * (number, dates, value, status) only (ADR-005, REQ-003).
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
 * Per-customer contract read facade.
 */
class PortalContractService extends AbstractPortalReadFacade
{
    /**
     * {@inheritDoc}
     *
     * @return string The schema key.
     */
    protected function schemaKey(): string
    {
        return 'contract';
    }//end schemaKey()

    /**
     * {@inheritDoc}
     *
     * @return string The scope.
     */
    protected function delegationScope(): string
    {
        return 'view-contracts';
    }//end delegationScope()

    /**
     * {@inheritDoc}
     *
     * @param array<string, mixed> $object The raw contract.
     *
     * @return array{0: string|null, 1: string|null} The [contactId, clientId].
     */
    protected function ownerIds(array $object): array
    {
        return [
            $this->readId(value: ($object['contact'] ?? null)),
            $this->readId(value: ($object['client'] ?? null)),
        ];
    }//end ownerIds()

    /**
     * {@inheritDoc}
     *
     * @param array<string, mixed> $object        The raw contract.
     * @param string|null          $delegatedFrom The grantor id, or null.
     *
     * @return array<string, mixed> The contract row.
     */
    protected function present(array $object, ?string $delegatedFrom): array
    {
        return [
            'id'             => $this->objectId(object: $object),
            'contractNumber' => ($object['contractNumber'] ?? $object['reference'] ?? null),
            'startDate'      => ($object['startDate'] ?? null),
            'endDate'        => ($object['endDate'] ?? null),
            'value'          => ($object['value'] ?? null),
            'status'         => ($object['status'] ?? null),
            'delegatedFrom'  => $delegatedFrom,
        ];
    }//end present()

    /**
     * {@inheritDoc}
     *
     * @param array<string, mixed> $object The raw contract.
     *
     * @return string The sort key.
     */
    protected function sortKey(array $object): string
    {
        return (string) ($object['startDate'] ?? '');
    }//end sortKey()
}//end class
