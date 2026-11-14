<?php

/**
 * Pipelinq AbstractPosTransactionFacade.
 *
 * Shared base of the portal facades that read a customer\'s POS transactions
 * (orders and invoices): the schema, the delegation scope and the owning client.
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

/**
 * Per-customer read facade over posTransaction rows, owned by their client and
 * widened by the `view-invoices` delegation. Subclasses present the rows.
 *
 * @spec openspec/changes/archive/2026-06-14-customer-portal/specs.md#REQ-003
 */
abstract class AbstractPosTransactionFacade extends AbstractPortalReadFacade {
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
}//end class
