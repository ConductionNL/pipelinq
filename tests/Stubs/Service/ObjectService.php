<?php

/**
 * Test stub for OCA\OpenRegister\Service\ObjectService.
 *
 * Provides a class declaration so that unit tests running in a bare
 * environment (no Nextcloud server, no openregister installed) can still type-
 * hint against the class and create PHPUnit mocks for it.
 *
 * This stub is loaded via the PSR-4 mapping registered in tests/bootstrap.php
 * ("OCA\\OpenRegister\\" => "tests/Stubs/") and is a no-op when the real
 * openregister app is present (class_exists guard).
 *
 * 🔑 It DECLARES `implements ObjectServiceInterface`, exactly as the real
 * OpenRegister ObjectService does (ADR-084). That is load-bearing in two
 * directions:
 *
 *   1. every `createMock(ObjectService::class)` then satisfies a
 *      `ObjectServiceInterface` type-hint, which is what production now asks
 *      for; and
 *   2. the interface is the REAL one, resolved from
 *      vendor/conduction/hydra-gates/hydra-gates/contracts/ — so this stub
 *      cannot silently drift from the published contract. If the contract
 *      moves, PHP fails to declare this class rather than letting a test
 *      "prove" a signature nobody ships.
 *
 * Every method below therefore mirrors the contract signature exactly. The
 * bodies are inert defaults; tests either mock this class or extend it and
 * override the handful of methods they exercise.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Stubs\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IUser;

if (class_exists(ObjectService::class) === false) {
	/**
	 * Stub class for ObjectService — used only in standalone unit tests.
	 *
	 * Replaced by the real implementation when the openregister app is installed.
	 */
	class ObjectService implements ObjectServiceInterface {
		/**
		 * Save (create or update) an object.
		 *
		 * @param array $object The object body.
		 * @param ?array $extend Relations to expand.
		 * @param string|int|null $register Register id, UUID or slug.
		 * @param string|int|null $schema Schema id, UUID or slug.
		 * @param ?string $uuid Existing object UUID, or null to create.
		 * @param bool $_rbac Apply register RBAC.
		 * @param bool $_multitenancy Apply organisation scoping.
		 * @param bool $silent Suppress events and audit.
		 * @param bool $_validation Validate against the schema.
		 * @param ?array $uploadedFiles Files to attach.
		 * @param ?IUser $currentUser Acting user.
		 * @param bool $failIfExists Refuse to overwrite.
		 *
		 * @return ObjectEntityInterface The saved object.
		 */
		public function saveObject(
			array $object,
			?array $extend=[],
			string|int|null $register=null,
			string|int|null $schema=null,
			?string $uuid=null,
			bool $_rbac=true,
			bool $_multitenancy=true,
			bool $silent=false,
			bool $_validation=true,
			?array $uploadedFiles=null,
			?IUser $currentUser=null,
			bool $failIfExists=false
		): ObjectEntityInterface {
			return new ObjectEntity();
		}//end saveObject()

		/**
		 * Set the active register (fluent).
		 *
		 * @param string|int $register Register id, UUID or slug.
		 *
		 * @return static
		 */
		public function setRegister(string|int $register): static {
			return $this;
		}//end setRegister()

		/**
		 * Find a single object.
		 *
		 * @param int|string $id Object id or UUID.
		 * @param ?array $_extend Relations to expand.
		 * @param bool $files Include file metadata.
		 * @param string|int|null $register Register id, UUID or slug.
		 * @param string|int|null $schema Schema id, UUID or slug.
		 * @param bool $_rbac Apply register RBAC.
		 * @param bool $_multitenancy Apply organisation scoping.
		 * @param bool $_render Render before returning.
		 * @param bool $_audit Write an audit-trail entry.
		 *
		 * @return ?ObjectEntityInterface The object, or null.
		 */
		public function find(
			int|string $id,
			?array $_extend=[],
			bool $files=false,
			string|int|null $register=null,
			string|int|null $schema=null,
			bool $_rbac=true,
			bool $_multitenancy=true,
			bool $_render=true,
			bool $_audit=true
		): ?ObjectEntityInterface {
			return null;
		}//end find()

		/**
		 * Find every object matching a configuration.
		 *
		 * @param array $config Filters, limit, offset, sort and search.
		 * @param bool $_rbac Apply register RBAC.
		 * @param bool $_multitenancy Apply organisation scoping.
		 *
		 * @return array The matching objects.
		 */
		public function findAll(
			array $config=[],
			bool $_rbac=true,
			bool $_multitenancy=true
		): array {
			return [];
		}//end findAll()

		/**
		 * Set the active schema (fluent).
		 *
		 * @param string|int $schema Schema id, UUID or slug.
		 *
		 * @return static
		 */
		public function setSchema(string|int $schema): static {
			return $this;
		}//end setSchema()

		/**
		 * Search objects.
		 *
		 * @param array $query The search query.
		 * @param bool $_rbac Apply register RBAC.
		 * @param bool $_multitenancy Apply organisation scoping.
		 * @param ?array $ids Restrict to these ids.
		 * @param ?string $uses Restrict to users of this object.
		 * @param ?array $views Views to apply.
		 *
		 * @return array|int The results, or a count.
		 */
		public function searchObjects(
			array $query=[],
			bool $_rbac=true,
			bool $_multitenancy=true,
			?array $ids=null,
			?string $uses=null,
			?array $views=null
		): array|int {
			return [];
		}//end searchObjects()

		/**
		 * Delete an object.
		 *
		 * @param string $uuid The object UUID.
		 * @param string|int|null $register Register id, UUID or slug.
		 * @param string|int|null $schema Schema id, UUID or slug.
		 * @param bool $_rbac Apply register RBAC.
		 * @param bool $_multitenancy Apply organisation scoping.
		 * @param bool $_retentionSweep Part of a retention sweep.
		 * @param ?IUser $currentUser Acting user.
		 * @param bool $permanent Hard-delete.
		 *
		 * @return bool Whether the object was deleted.
		 */
		public function deleteObject(
			string $uuid,
			string|int|null $register=null,
			string|int|null $schema=null,
			bool $_rbac=true,
			bool $_multitenancy=true,
			bool $_retentionSweep=false,
			?IUser $currentUser=null,
			bool $permanent=false
		): bool {
			return true;
		}//end deleteObject()

		/**
		 * Search objects with pagination metadata.
		 *
		 * @param array $query The search query.
		 * @param bool $_rbac Apply register RBAC.
		 * @param bool $_multitenancy Apply organisation scoping.
		 * @param bool $deleted Include deleted objects.
		 * @param ?array $ids Restrict to these ids.
		 * @param ?string $uses Restrict to users of this object.
		 * @param ?array $views Views to apply.
		 *
		 * @return array The paginated results.
		 */
		public function searchObjectsPaginated(
			array $query=[],
			bool $_rbac=true,
			bool $_multitenancy=true,
			bool $deleted=false,
			?array $ids=null,
			?string $uses=null,
			?array $views=null
		): array {
			return [
				'results' => [],
				'total'   => 0,
			];
		}//end searchObjectsPaginated()

		/**
		 * Search objects by register and schema slug.
		 *
		 * @param string $registerSlug The register slug.
		 * @param string $schemaSlug The schema slug.
		 * @param array $filters Filters to apply.
		 * @param bool $_rbac Apply register RBAC.
		 * @param bool $_multitenancy Apply organisation scoping.
		 *
		 * @return array|int The results, or a count.
		 */
		public function searchObjectsBySlug(
			string $registerSlug,
			string $schemaSlug,
			array $filters=[],
			bool $_rbac=true,
			bool $_multitenancy=true
		): array|int {
			return [];
		}//end searchObjectsBySlug()

		/**
		 * Clear the current register and schema.
		 *
		 * @return void
		 */
		public function clearCurrents(): void {
		}//end clearCurrents()

		/**
		 * Build a search query from request parameters.
		 *
		 * @param array $requestParams The request parameters.
		 * @param int|string|array|null $register Register id, UUID or slug.
		 * @param int|string|array|null $schema Schema id, UUID or slug.
		 * @param ?array $ids Restrict to these ids.
		 *
		 * @return array The query.
		 */
		public function buildSearchQuery(
			array $requestParams,
			int|string|array|null $register=null,
			int|string|array|null $schema=null,
			?array $ids=null
		): array {
			return [];
		}//end buildSearchQuery()

		/**
		 * Save many objects at once.
		 *
		 * @param array $objects The object bodies.
		 * @param string|int|null $register Register id, UUID or slug.
		 * @param string|int|null $schema Schema id, UUID or slug.
		 * @param bool $_rbac Apply register RBAC.
		 * @param bool $_multitenancy Apply organisation scoping.
		 * @param bool $validation Validate against the schema.
		 * @param bool $events Dispatch events.
		 * @param bool $deduplicateIds Drop duplicate ids.
		 * @param bool $enrich Enrich the results.
		 * @param bool $_audit Write audit-trail entries.
		 *
		 * @return array The saved objects.
		 */
		public function saveObjects(
			array $objects,
			string|int|null $register=null,
			string|int|null $schema=null,
			bool $_rbac=true,
			bool $_multitenancy=true,
			bool $validation=false,
			bool $events=false,
			bool $deduplicateIds=true,
			bool $enrich=true,
			bool $_audit=true
		): array {
			return [];
		}//end saveObjects()

		/**
		 * Run an operation with system privileges.
		 *
		 * @param callable $operation The operation to run.
		 *
		 * @return mixed The operation result.
		 */
		public function runAsSystem(callable $operation) {
			return $operation();
		}//end runAsSystem()

		/**
		 * Count objects matching a configuration.
		 *
		 * @param array $config Filters and search.
		 *
		 * @return int The count.
		 */
		public function count(array $config=[]): int {
			return 0;
		}//end count()

		/**
		 * Release a lock on an object.
		 *
		 * @param string|int $identifier The object id or UUID.
		 * @param bool $advisory Advisory lock.
		 *
		 * @return bool Whether the lock was released.
		 */
		public function unlockObject(string|int $identifier, bool $advisory=false): bool {
			return true;
		}//end unlockObject()

		/**
		 * Take a lock on an object.
		 *
		 * @param string $identifier The object id or UUID.
		 * @param ?string $process The process holding the lock.
		 * @param ?int $duration Lock duration in seconds.
		 * @param bool $advisory Advisory lock.
		 *
		 * @return array The lock descriptor.
		 */
		public function lockObject(
			string $identifier,
			?string $process=null,
			?int $duration=null,
			bool $advisory=false
		): array {
			return [];
		}//end lockObject()

		/**
		 * Delete many objects at once.
		 *
		 * @param array $uuids The object UUIDs.
		 * @param bool $_rbac Apply register RBAC.
		 * @param bool $_multitenancy Apply organisation scoping.
		 *
		 * @return array The per-object results.
		 */
		public function deleteObjects(
			array $uuids=[],
			bool $_rbac=true,
			bool $_multitenancy=true
		): array {
			return [];
		}//end deleteObjects()

		/**
		 * Read an object's audit trail.
		 *
		 * @param string $uuid The object UUID.
		 * @param array $filters Filters to apply.
		 * @param bool $_rbac Apply register RBAC.
		 * @param bool $_multitenancy Apply organisation scoping.
		 *
		 * @return array The log entries.
		 */
		public function getLogs(
			string $uuid,
			array $filters=[],
			bool $_rbac=true,
			bool $_multitenancy=true
		): array {
			return [];
		}//end getLogs()

		/**
		 * Update an existing object.
		 *
		 * @param string $objectId The object id or UUID.
		 * @param array $data The new body.
		 * @param bool $_rbac Apply register RBAC.
		 * @param bool $_multitenancy Apply organisation scoping.
		 *
		 * @return ObjectEntityInterface The updated object.
		 */
		public function updateObject(
			string $objectId,
			array $data,
			bool $_rbac=true,
			bool $_multitenancy=true
		): ObjectEntityInterface {
			return new ObjectEntity();
		}//end updateObject()

		/**
		 * List the objects this object refers to.
		 *
		 * @param string $objectId The object id or UUID.
		 * @param array $query Filters to apply.
		 * @param bool $_rbac Apply register RBAC.
		 * @param bool $_multitenancy Apply organisation scoping.
		 *
		 * @return array The related objects.
		 */
		public function getObjectUses(
			string $objectId,
			array $query=[],
			bool $_rbac=true,
			bool $_multitenancy=true
		): array {
			return [];
		}//end getObjectUses()

		/**
		 * List the objects referring to this object.
		 *
		 * @param string $objectId The object id or UUID.
		 * @param array $query Filters to apply.
		 * @param bool $_rbac Apply register RBAC.
		 * @param bool $_multitenancy Apply organisation scoping.
		 *
		 * @return array The referring objects.
		 */
		public function getObjectUsedBy(
			string $objectId,
			array $query=[],
			bool $_rbac=true,
			bool $_multitenancy=true
		): array {
			return [];
		}//end getObjectUsedBy()

		/**
		 * Find objects by relation.
		 *
		 * @param string $search The relation to search for.
		 * @param bool $partialMatch Allow partial matches.
		 *
		 * @return array The matching objects.
		 */
		public function findByRelations(string $search, bool $partialMatch=true): array {
			return [];
		}//end findByRelations()

		/**
		 * Find a single object without writing an audit entry.
		 *
		 * @param string $id The object id or UUID.
		 * @param ?array $_extend Relations to expand.
		 * @param bool $files Include file metadata.
		 * @param string|int|null $register Register id, UUID or slug.
		 * @param string|int|null $schema Schema id, UUID or slug.
		 * @param bool $_rbac Apply register RBAC.
		 * @param bool $_multitenancy Apply organisation scoping.
		 *
		 * @return ObjectEntityInterface The object.
		 */
		public function findSilent(
			string $id,
			?array $_extend=[],
			bool $files=false,
			string|int|null $register=null,
			string|int|null $schema=null,
			bool $_rbac=true,
			bool $_multitenancy=true
		): ObjectEntityInterface {
			return new ObjectEntity();
		}//end findSilent()

		/**
		 * Count the results of a search.
		 *
		 * @param array $query The search query.
		 * @param bool $_rbac Apply register RBAC.
		 * @param bool $_multitenancy Apply organisation scoping.
		 * @param ?array $ids Restrict to these ids.
		 * @param ?string $uses Restrict to users of this object.
		 *
		 * @return int The count.
		 */
		public function countSearchObjects(
			array $query=[],
			bool $_rbac=true,
			bool $_multitenancy=true,
			?array $ids=null,
			?string $uses=null
		): int {
			return 0;
		}//end countSearchObjects()

		/**
		 * Get the currently-held object.
		 *
		 * @return ?ObjectEntityInterface The object, or null.
		 */
		public function getObject(): ?ObjectEntityInterface {
			return null;
		}//end getObject()
	}//end class
}//end if
