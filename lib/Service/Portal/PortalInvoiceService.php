<?php

/**
 * Pipelinq PortalInvoiceService.
 *
 * Read facade exposing a customer's invoices in the portal. Invoices are the
 * confirmed POS transactions (posTransaction) of the customer's linked client /
 * contact; the facade reads them from the main register, filtered server-side
 * to the customer's own data plus any data delegated under the `view-invoices`
 * scope, and presents only the safe fields (number, date, amount, status) in
 * Dutch-labelled portal shape (ADR-005, REQ-003 / REQ-005).
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
 * Per-customer invoice read facade.
 */
class PortalInvoiceService extends AbstractPortalReadFacade {
	/**
	 * {@inheritDoc}
	 *
	 * @return string The schema key.
	 */
	protected function schemaKey(): string {
		return 'posTransaction';
	}//end schemaKey()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The scope.
	 */
	protected function delegationScope(): string {
		return 'view-invoices';
	}//end delegationScope()

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $object The raw posTransaction.
	 *
	 * @return array{0: string|null, 1: string|null} The [contactId, clientId].
	 */
	protected function ownerIds(array $object): array {
		return [null, $this->readId(value: ($object['client'] ?? null))];
	}//end ownerIds()

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $object The raw posTransaction.
	 * @param string|null $delegatedFrom The grantor id, or null.
	 *
	 * @return array<string, mixed> The invoice row.
	 */
	protected function present(array $object, ?string $delegatedFrom): array {
		return [
			'id' => $this->objectId(object: $object),
			'invoiceNumber' => ($object['invoiceNumber'] ?? $object['reference'] ?? null),
			'date' => ($object['confirmedAt'] ?? $object['settledAt'] ?? null),
			'amount' => ($object['total'] ?? null),
			'status' => ($object['status'] ?? null),
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
	protected function sortKey(array $object): string {
		return (string)($object['confirmedAt'] ?? $object['settledAt'] ?? '');
	}//end sortKey()
}//end class
