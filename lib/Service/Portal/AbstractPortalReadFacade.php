<?php

/**
 * Pipelinq AbstractPortalReadFacade.
 *
 * Shared machinery for the portal's read-only data facades (invoices,
 * contracts, orders): resolve the account's per-scope read boundary, pull the
 * candidate objects from the main register, drop anything the account may not
 * see, tag delegated rows with `delegatedFrom`, sort newest-first, and
 * paginate. Concrete facades only declare which schema, scope, owner fields and
 * output shape they use, so the IDOR-safe filtering lives in exactly one place
 * (ADR-005, REQ-003).
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
 * Base class for per-customer scoped read facades.
 */
abstract class AbstractPortalReadFacade {
	/**
	 * Constructor.
	 *
	 * @param MainRegisterReader $reader The main-register reader.
	 * @param PortalScopeResolver $scope The scope resolver.
	 */
	public function __construct(
		protected MainRegisterReader $reader,
		protected PortalScopeResolver $scope,
	) {
	}//end __construct()

	/**
	 * The main-register schema config key this facade reads (e.g. posTransaction_schema).
	 *
	 * @return string The schema key.
	 */
	abstract protected function schemaKey(): string;

	/**
	 * The delegation scope that widens this facade's visibility.
	 *
	 * @return string The scope (e.g. view-invoices).
	 */
	abstract protected function delegationScope(): string;

	/**
	 * Extract the owning [contactId, clientId] from a raw object.
	 *
	 * @param array<string, mixed> $object The raw object.
	 *
	 * @return array{0: string|null, 1: string|null} The owner ids.
	 */
	abstract protected function ownerIds(array $object): array;

	/**
	 * Map a raw object to the portal API output shape.
	 *
	 * @param array<string, mixed> $object The raw object.
	 * @param string|null $delegatedFrom The grantor id, or null for own.
	 *
	 * @return array<string, mixed> The output row.
	 */
	abstract protected function present(array $object, ?string $delegatedFrom): array;

	/**
	 * The field used to sort newest-first (ISO-8601 string).
	 *
	 * @param array<string, mixed> $object The raw object.
	 *
	 * @return string The sort key.
	 */
	abstract protected function sortKey(array $object): string;

	/**
	 * List the account's accessible rows, paginated and newest-first.
	 *
	 * @param array<string, mixed> $account The authenticated account.
	 * @param int $page The 1-based page.
	 * @param int $perPage The page size.
	 *
	 * @return array{total: int, page: int, perPage: int, items: array<int, array<string, mixed>>}
	 */
	public function getForAccount(array $account, int $page = 1, int $perPage = 10): array {
		$page = max(1, $page);
		$perPage = min(100, max(1, $perPage));

		$resolved = $this->scope->resolve($account, $this->delegationScope());
		$rows = [];

		foreach ($this->reader->findAll($this->schemaKey()) as $object) {
			[$contactId, $clientId] = $this->ownerIds(object: $object);
			$classification = $this->scope->classify(resolved: $resolved, contactId: $contactId, clientId: $clientId);
			if ($classification === false) {
				continue;
			}

			$rows[] = [
				'sort' => $this->sortKey(object: $object),
				'present' => $this->present(object: $object, delegatedFrom: $classification),
			];
		}

		usort($rows, static fn (array $left, array $right): int => strcmp($right['sort'], $left['sort']));

		$total = count($rows);
		$slice = array_slice($rows, (($page - 1) * $perPage), $perPage);

		return [
			'total' => $total,
			'page' => $page,
			'perPage' => $perPage,
			'items' => array_map(static fn (array $row): array => $row['present'], $slice),
		];
	}//end getForAccount()

	/**
	 * Fetch a single accessible row by id, or null when not visible.
	 *
	 * Returns null (the caller maps to 404) for both "does not exist" and "not
	 * yours", so an id from another customer/tenant cannot be distinguished
	 * from a non-existent one (no existence leak).
	 *
	 * @param array<string, mixed> $account The authenticated account.
	 * @param string $id The object id.
	 *
	 * @return array<string, mixed>|null The row, or null.
	 */
	public function getOneForAccount(array $account, string $id): ?array {
		$object = $this->reader->find($this->schemaKey(), $id);
		if ($object === null) {
			return null;
		}

		$resolved = $this->scope->resolve($account, $this->delegationScope());
		[$contactId, $clientId] = $this->ownerIds(object: $object);
		$classification = $this->scope->classify(resolved: $resolved, contactId: $contactId, clientId: $clientId);
		if ($classification === false) {
			return null;
		}

		return $this->present(object: $object, delegatedFrom: $classification);
	}//end getOneForAccount()

	/**
	 * Read a possibly-nested id value (string or {id|uuid}) into a string.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null The id, or null.
	 */
	protected function readId(mixed $value): ?string {
		if ($value === null || $value === '') {
			return null;
		}

		if (is_array($value) === true) {
			$id = ($value['id'] ?? $value['uuid'] ?? null);
			if ($id === null) {
				return null;
			}

			return (string)$id;
		}

		return (string)$value;
	}//end readId()

	/**
	 * Extract the stable id from a raw object's `@self` envelope or top level.
	 *
	 * @param array<string, mixed> $object The raw object.
	 *
	 * @return string|null The id, or null.
	 */
	protected function objectId(array $object): ?string {
		$self = ($object['@self'] ?? []);
		if (is_array($self) === true) {
			$id = ($self['id'] ?? $self['uuid'] ?? null);
			if ($id !== null) {
				return (string)$id;
			}
		}

		$id = ($object['id'] ?? $object['uuid'] ?? null);
		if ($id === null) {
			return null;
		}

		return (string)$id;
	}//end objectId()
}//end class
