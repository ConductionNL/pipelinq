<?php

/**
 * Pipelinq PortalOrderService.
 *
 * Read facade exposing a customer's orders in the portal. Orders are derived
 * from the customer's POS transactions (posTransaction) of their linked client,
 * filtered server-side to own + `view-invoices`-delegated data, and presented as
 * order rows (number, date, total, status). Orders share the POS schema with
 * invoices but expose an order-oriented projection (REQ-003).
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
 * Per-customer order read facade.
 */
class PortalOrderService extends AbstractPortalReadFacade
{
    /**
     * {@inheritDoc}
     *
     * @return string The schema key.
     */
    protected function schemaKey(): string
    {
        return 'posTransaction';
    }//end schemaKey()

    /**
     * {@inheritDoc}
     *
     * @return string The scope.
     */
    protected function delegationScope(): string
    {
        return 'view-invoices';
    }//end delegationScope()

    /**
     * {@inheritDoc}
     *
     * @param array<string, mixed> $object The raw posTransaction.
     *
     * @return array{0: string|null, 1: string|null} The [contactId, clientId].
     */
    protected function ownerIds(array $object): array
    {
        return [null, $this->readId(value: ($object['client'] ?? null))];
    }//end ownerIds()

    /**
     * {@inheritDoc}
     *
     * @param array<string, mixed> $object        The raw posTransaction.
     * @param string|null          $delegatedFrom The grantor id, or null.
     *
     * @return array<string, mixed> The order row.
     */
    protected function present(array $object, ?string $delegatedFrom): array
    {
        return [
            'id'            => $this->objectId(object: $object),
            'orderNumber'   => ($object['reference'] ?? $object['invoiceNumber'] ?? null),
            'date'          => ($object['confirmedAt'] ?? $object['parkedAt'] ?? null),
            'total'         => ($object['total'] ?? null),
            'status'        => ($object['status'] ?? null),
            'delegatedFrom' => $delegatedFrom,
        ];
    }//end present()

    /**
     * {@inheritDoc}
     *
     * @param array<string, mixed> $object The raw posTransaction.
     *
     * @return string The sort key.
     */
    protected function sortKey(array $object): string
    {
        return (string) ($object['confirmedAt'] ?? $object['parkedAt'] ?? '');
    }//end sortKey()
}//end class
