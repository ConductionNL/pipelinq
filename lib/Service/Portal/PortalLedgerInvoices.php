<?php

/**
 * Pipelinq PortalLedgerInvoices.
 *
 * The invoices the bookkeeping app (shillinq) holds for the clients a portal
 * account may see. A client names its bookkeeping customer in
 * `shillinqOrganisationRef`; this reads that customer's invoices past the
 * draft stage through `ShillinqInvoiceReader` and presents them in the
 * portal's invoice shape, tagged with the grantor when the client was
 * delegated. Without the bookkeeping app, or for a client without a
 * reference, there is nothing to add.
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
 * @spec openspec/changes/specify-capability-rows/specs/customer-portal/spec.md#requirement-a-client-sees-their-own-invoices-contracts-orders-and-quotes
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use OCA\Pipelinq\Service\ShillinqInvoiceReader;

/**
 * Bookkeeping invoices of the clients in a resolved portal scope.
 *
 * @spec openspec/changes/specify-capability-rows/specs/customer-portal/spec.md#requirement-a-client-sees-their-own-invoices-contracts-orders-and-quotes
 */
class PortalLedgerInvoices {
	/**
	 * The `source` value a bookkeeping invoice carries in the portal.
	 *
	 * @var string
	 */
	public const SOURCE = 'ledger';

	/**
	 * Constructor.
	 *
	 * @param MainRegisterReader    $reader The main-register reader, for the client rows.
	 * @param ShillinqInvoiceReader $ledger The bookkeeping invoice reader.
	 *
	 * @spec openspec/changes/specify-capability-rows/specs/customer-portal/spec.md#requirement-a-client-sees-their-own-invoices-contracts-orders-and-quotes
	 */
	public function __construct(
		private MainRegisterReader $reader,
		private ShillinqInvoiceReader $ledger,
	) {
	}//end __construct()

	/**
	 * The bookkeeping invoices of every client in the scope, each once, with
	 * its sort key (the invoice date) and its portal shape. Own clients come
	 * first, so an invoice of a client that is both own and delegated reads
	 * as own.
	 *
	 * @param array<string, mixed> $resolved The resolved scope from PortalScopeResolver::resolve().
	 *
	 * @return array<int, array{sort: string, present: array<string, mixed>}> The rows, unsorted.
	 *
	 * @spec openspec/changes/specify-capability-rows/specs/customer-portal/spec.md#requirement-a-client-sees-their-own-invoices-contracts-orders-and-quotes
	 */
	public function rowsFor(array $resolved): array {
		if ($this->ledger->isAvailable() === false) {
			return [];
		}

		$rows = [];
		foreach ($this->clientsIn(resolved: $resolved) as [$clientId, $delegatedFrom]) {
			foreach ($this->invoicesOf(clientId: $clientId) as $invoice) {
				$id = (string)($invoice['id'] ?? '');
				if ($id === '' || isset($rows[$id]) === true) {
					continue;
				}

				$rows[$id] = [
					'sort' => (string)($invoice['invoiceDate'] ?? ''),
					'present' => $this->present(invoice: $invoice, delegatedFrom: $delegatedFrom),
				];
			}
		}

		return array_values($rows);
	}//end rowsFor()

	/**
	 * One bookkeeping invoice of a client in the scope, or null when no
	 * client in the scope has it: another customer's invoice id reads the
	 * same as one that does not exist.
	 *
	 * @param array<string, mixed> $resolved The resolved scope.
	 * @param string               $id       The invoice id.
	 *
	 * @return array<string, mixed>|null The invoice in portal shape, or null.
	 *
	 * @spec openspec/changes/specify-capability-rows/specs/customer-portal/spec.md#requirement-a-client-sees-their-own-invoices-contracts-orders-and-quotes
	 */
	public function findFor(array $resolved, string $id): ?array {
		if ($id === '') {
			return null;
		}

		foreach ($this->rowsFor(resolved: $resolved) as $row) {
			if ($row['present']['id'] === $id) {
				return $row['present'];
			}
		}

		return null;
	}//end findFor()

	/**
	 * The client ids of the scope, each with null (own) or the grantor.
	 *
	 * @param array<string, mixed> $resolved The resolved scope.
	 *
	 * @return array<int, array{0: string, 1: string|null}> Client id and delegatedFrom pairs.
	 */
	private function clientsIn(array $resolved): array {
		$clients = [];
		foreach (($resolved['ownClientIds'] ?? []) as $clientId) {
			$clients[] = [(string)$clientId, null];
		}

		foreach (($resolved['delegated'] ?? []) as $entry) {
			foreach (($entry['clientIds'] ?? []) as $clientId) {
				$clients[] = [(string)$clientId, (string)$entry['from']];
			}
		}

		return $clients;
	}//end clientsIn()

	/**
	 * The bookkeeping invoices of one client, none without a reference.
	 *
	 * @param string $clientId The client id.
	 *
	 * @return array<int, array<string, mixed>> The invoices past the draft stage.
	 */
	private function invoicesOf(string $clientId): array {
		$client = $this->reader->find('client', $clientId);
		$customerRef = trim((string)(($client ?? [])['shillinqOrganisationRef'] ?? ''));
		if ($customerRef === '') {
			return [];
		}

		return $this->ledger->invoicesFor(customerRef: $customerRef);
	}//end invoicesOf()

	/**
	 * A bookkeeping invoice in the portal's invoice shape.
	 *
	 * @param array<string, mixed> $invoice       The invoice from ShillinqInvoiceReader::invoicesFor().
	 * @param string|null          $delegatedFrom The grantor, or null for own.
	 *
	 * @return array<string, mixed> The invoice row.
	 */
	private function present(array $invoice, ?string $delegatedFrom): array {
		return [
			'id' => (string)$invoice['id'],
			'invoiceNumber' => $this->textOrNull(value: ($invoice['invoiceNumber'] ?? null)),
			'date' => $this->textOrNull(value: ($invoice['invoiceDate'] ?? null)),
			'amount' => ($invoice['amount'] ?? null),
			'currency' => (string)($invoice['currency'] ?? 'EUR'),
			'status' => ($invoice['lifecycleState'] ?? null),
			'source' => self::SOURCE,
			'delegatedFrom' => $delegatedFrom,
		];
	}//end present()

	/**
	 * A value as text, or null when it is empty.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The text, or null.
	 */
	private function textOrNull(mixed $value): ?string {
		$text = trim((string)$value);
		if ($text === '') {
			return null;
		}

		return $text;
	}//end textOrNull()
}//end class
