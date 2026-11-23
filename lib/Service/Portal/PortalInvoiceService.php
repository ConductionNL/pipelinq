<?php

/**
 * Pipelinq PortalInvoiceService.
 *
 * Read facade exposing a customer's invoices in the portal. Invoices are the
 * POS transactions (posTransaction) of the customer's linked client past the
 * cart stage (a draft or parked cart is not an invoice), plus the invoices the
 * bookkeeping app holds for that client (PortalLedgerInvoices). Both are
 * filtered server-side to the customer's own data plus any data delegated
 * under the `view-invoices` scope, and presented with only the safe fields
 * (number, date, amount, status, source) (ADR-005, REQ-003 / REQ-005).
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

use OCA\Pipelinq\Kernel\Contracts\PortalInvoiceServiceInterface;

/**
 * Per-customer invoice read facade.
 *
 * @spec openspec/changes/specify-capability-rows/specs/customer-portal/spec.md#requirement-a-client-sees-their-own-invoices-contracts-orders-and-quotes
 */
class PortalInvoiceService extends AbstractPosTransactionFacade implements PortalInvoiceServiceInterface {
	/**
	 * The POS states of a cart that was never turned into an invoice.
	 *
	 * @var array<int, string>
	 */
	public const CART_STATES = ['draft', 'parked'];

	/**
	 * The `source` value a POS invoice carries in the portal.
	 *
	 * @var string
	 */
	public const SOURCE = 'pos';

	/**
	 * Constructor.
	 *
	 * @param MainRegisterReader        $reader The main-register reader.
	 * @param PortalScopeResolver       $scope  The scope resolver.
	 * @param PortalLedgerInvoices|null $ledger The bookkeeping invoices, or null for POS invoices only.
	 *
	 * @spec openspec/changes/specify-capability-rows/specs/customer-portal/spec.md#requirement-a-client-sees-their-own-invoices-contracts-orders-and-quotes
	 */
	public function __construct(
		MainRegisterReader $reader,
		PortalScopeResolver $scope,
		private ?PortalLedgerInvoices $ledger = null,
	) {
		parent::__construct(reader: $reader, scope: $scope);
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * Adds the bookkeeping invoice when no POS invoice has the id.
	 *
	 * @param array<string, mixed> $account The authenticated account.
	 * @param string               $id      The invoice id.
	 *
	 * @return array<string, mixed>|null The invoice, or null.
	 *
	 * @spec openspec/changes/specify-capability-rows/specs/customer-portal/spec.md#requirement-a-client-sees-their-own-invoices-contracts-orders-and-quotes
	 */
	public function getOneForAccount(array $account, string $id): ?array {
		$invoice = parent::getOneForAccount(account: $account, id: $id);
		if ($invoice !== null || $this->ledger === null) {
			return $invoice;
		}

		return $this->ledger->findFor(resolved: $this->scope->resolve($account, $this->delegationScope()), id: $id);
	}//end getOneForAccount()

	/**
	 * {@inheritDoc}
	 *
	 * The POS invoices, then the bookkeeping invoices of the same clients.
	 *
	 * @param array<string, mixed> $resolved The resolved scope.
	 *
	 * @return array<int, array{sort: string, present: array<string, mixed>}> The visible rows.
	 *
	 * @spec openspec/changes/specify-capability-rows/specs/customer-portal/spec.md#requirement-a-client-sees-their-own-invoices-contracts-orders-and-quotes
	 */
	protected function visibleRows(array $resolved): array {
		$rows = parent::visibleRows(resolved: $resolved);
		if ($this->ledger === null) {
			return $rows;
		}

		return array_merge($rows, $this->ledger->rowsFor(resolved: $resolved));
	}//end visibleRows()

	/**
	 * {@inheritDoc}
	 *
	 * A draft or parked cart is not an invoice.
	 *
	 * @param array<string, mixed> $object The raw posTransaction.
	 *
	 * @return bool False for a cart.
	 *
	 * @spec openspec/changes/specify-capability-rows/specs/customer-portal/spec.md#requirement-a-client-sees-their-own-invoices-contracts-orders-and-quotes
	 */
	protected function isListed(array $object): bool {
		return in_array(strtolower(trim((string)($object['status'] ?? ''))), self::CART_STATES, true) === false;
	}//end isListed()

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $object The raw posTransaction.
	 * @param string|null $delegatedFrom The grantor id, or null.
	 *
	 * @return array<string, mixed> The invoice row.
	 *
	 * @spec openspec/changes/specify-capability-rows/specs/customer-portal/spec.md#requirement-a-client-sees-their-own-invoices-contracts-orders-and-quotes
	 */
	protected function present(array $object, ?string $delegatedFrom): array {
		return [
			'id' => $this->objectId(object: $object),
			'invoiceNumber' => ($object['invoiceNumber'] ?? $object['reference'] ?? null),
			'date' => ($object['confirmedAt'] ?? $object['settledAt'] ?? null),
			'amount' => ($object['total'] ?? null),
			'status' => ($object['status'] ?? null),
			'source' => self::SOURCE,
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
