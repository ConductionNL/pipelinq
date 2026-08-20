<?php

/**
 * Test stub for OCA\OpenRegister\Service\ObjectService.
 *
 * Provides a minimal class declaration so that unit tests running in a bare
 * environment (no Nextcloud server, no openregister installed) can still type-
 * hint against the class and create PHPUnit mocks for it.
 *
 * This stub is loaded via Composer's autoload-dev PSR-4 mapping
 * ("OCA\\OpenRegister\\" => "tests/Stubs/") and is a no-op when the real
 * openregister app is present (class_exists guard).
 *
 * IT MUST IMPLEMENT ObjectServiceInterface, AND ITS SIGNATURES MUST MATCH.
 *
 * The guard reads "no-op when the real app is present", but that is not what
 * happens under PHPUnit: this file is reachable through pipelinq's OWN
 * autoload-dev mapping, so it is loaded BEFORE anything pulls in the real
 * class, `class_exists()` is false at that moment, and the stub wins. Every
 * `createMock(ObjectService::class)` in this repo therefore mocks THIS class,
 * not OpenRegister's.
 *
 * The real ObjectService implements ObjectServiceInterface (ADR-084,
 * openregister#2498). While this stub did not, every mock of it failed the
 * contract type hints that ADR-084 introduced:
 *
 *   TypeError: ...::__construct(): Argument #N ($objectService) must be of type
 *   OCA\OpenRegister\Contract\ObjectServiceInterface, MockObject_ObjectService given
 *
 * A stub that stands in for a class must carry that class's method surface AND
 * its interfaces, or it is a different type wearing the same name.
 *
 * The bodies are deliberately inert — every caller mocks them. The three that
 * must return a non-nullable ObjectEntityInterface throw instead of
 * fabricating one, because a double that quietly returns the wrong shape is
 * worse than one that says it was not configured.
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

use LogicException;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IUser;

if (class_exists(ObjectService::class) === false) {
	/**
	 * Stub class for ObjectService — used only in standalone unit tests.
	 *
	 * Replaced by the real implementation when the openregister app is installed.
	 */
	class ObjectService implements ObjectServiceInterface {
		/**
		 * Message used by the three methods that cannot honour their return type inertly.
		 *
		 * @var string
		 */
		private const NOT_CONFIGURED = 'ObjectService test stub: this method returns an ObjectEntityInterface and has no inert value. Configure it on the mock.';

		/**
		 * Persist an object.
		 *
		 * @param array<string, mixed>       $object         The data to persist.
		 * @param array<string, mixed>|null  $extend         Additional field values.
		 * @param string|int|null            $register       Register slug or ID.
		 * @param string|int|null            $schema         Schema slug or ID.
		 * @param string|null                $uuid           UUID for update; null for create.
		 * @param boolean                    $_rbac          Whether to enforce RBAC checks.
		 * @param boolean                    $_multitenancy  Whether to enforce tenant scoping.
		 * @param boolean                    $silent         Whether to suppress side-effects.
		 * @param boolean                    $_validation    Whether to validate against the schema.
		 * @param array<string, mixed>|null  $uploadedFiles  Files to attach.
		 * @param IUser|null                 $currentUser    Acting user for folder access.
		 * @param boolean                    $failIfExists   Whether a duplicate is an error.
		 *
		 * @return ObjectEntityInterface
		 */
		public function saveObject(
			array $object,
			?array $extend = [],
			string|int|null $register = null,
			string|int|null $schema = null,
			?string $uuid = null,
			bool $_rbac = true,
			bool $_multitenancy = true,
			bool $silent = false,
			bool $_validation = true,
			?array $uploadedFiles = null,
			?IUser $currentUser = null,
			bool $failIfExists = false,
		): ObjectEntityInterface {
			throw new LogicException(self::NOT_CONFIGURED);
		}//end saveObject()

		/**
		 * Set the active register for subsequent calls (fluent API).
		 *
		 * @param string|int $register Register slug or ID.
		 *
		 * @return static
		 */
		public function setRegister(string|int $register): static {
			return $this;
		}//end setRegister()

		/**
		 * Find a single object.
		 *
		 * @param integer|string            $id            The object id or UUID.
		 * @param array<string, mixed>|null $_extend       Properties to expand.
		 * @param boolean                   $files         Whether to include files.
		 * @param string|int|null           $register      Register slug or ID.
		 * @param string|int|null           $schema        Schema slug or ID.
		 * @param boolean                   $_rbac         Whether to enforce RBAC checks.
		 * @param boolean                   $_multitenancy Whether to enforce tenant scoping.
		 * @param boolean                   $_render       Whether to render the result.
		 * @param boolean                   $_audit        Whether to write an audit trail.
		 *
		 * @return ObjectEntityInterface|null
		 */
		public function find(
			int|string $id,
			?array $_extend = [],
			bool $files = false,
			string|int|null $register = null,
			string|int|null $schema = null,
			bool $_rbac = true,
			bool $_multitenancy = true,
			bool $_render = true,
			bool $_audit = true,
		): ?ObjectEntityInterface {
			return null;
		}//end find()

		/**
		 * Find all objects matching the given configuration.
		 *
		 * @param array<string, mixed> $config        The query configuration.
		 * @param boolean              $_rbac         Whether to enforce RBAC checks.
		 * @param boolean              $_multitenancy Whether to enforce tenant scoping.
		 *
		 * @return array<int, mixed>
		 */
		public function findAll(
			array $config = [],
			bool $_rbac = true,
			bool $_multitenancy = true,
		): array {
			return [];
		}//end findAll()

		/**
		 * Set the active schema for subsequent calls (fluent API).
		 *
		 * @param string|int $schema Schema slug or ID.
		 *
		 * @return static
		 */
		public function setSchema(string|int $schema): static {
			return $this;
		}//end setSchema()

		/**
		 * Search objects.
		 *
		 * @param array<string, mixed>    $query         The search query.
		 * @param boolean                 $_rbac         Whether to enforce RBAC checks.
		 * @param boolean                 $_multitenancy Whether to enforce tenant scoping.
		 * @param array<int, mixed>|null  $ids           Restrict to these ids.
		 * @param string|null             $uses          Restrict to objects using this one.
		 * @param array<int, mixed>|null  $views         Views to apply.
		 *
		 * @return array<int, mixed>|int
		 */
		public function searchObjects(
			array $query = [],
			bool $_rbac = true,
			bool $_multitenancy = true,
			?array $ids = null,
			?string $uses = null,
			?array $views = null,
		): array|int {
			return [];
		}//end searchObjects()

		/**
		 * Delete an object by UUID.
		 *
		 * @param string          $uuid            The object UUID.
		 * @param string|int|null $register        Register slug or ID.
		 * @param string|int|null $schema          Schema slug or ID.
		 * @param boolean         $_rbac           Whether to enforce RBAC checks.
		 * @param boolean         $_multitenancy   Whether to enforce tenant scoping.
		 * @param boolean         $_retentionSweep Whether this is a retention sweep.
		 * @param IUser|null      $currentUser     Acting user.
		 * @param boolean         $permanent       Whether to delete permanently.
		 *
		 * @return boolean
		 */
		public function deleteObject(
			string $uuid,
			string|int|null $register = null,
			string|int|null $schema = null,
			bool $_rbac = true,
			bool $_multitenancy = true,
			bool $_retentionSweep = false,
			?IUser $currentUser = null,
			bool $permanent = false,
		): bool {
			return true;
		}//end deleteObject()

		/**
		 * Search objects with pagination.
		 *
		 * @param array<string, mixed>   $query         The search query.
		 * @param boolean                $_rbac         Whether to enforce RBAC checks.
		 * @param boolean                $_multitenancy Whether to enforce tenant scoping.
		 * @param boolean                $deleted       Whether to include deleted objects.
		 * @param array<int, mixed>|null $ids           Restrict to these ids.
		 * @param string|null            $uses          Restrict to objects using this one.
		 * @param array<int, mixed>|null $views         Views to apply.
		 *
		 * @return array<string, mixed>
		 */
		public function searchObjectsPaginated(
			array $query = [],
			bool $_rbac = true,
			bool $_multitenancy = true,
			bool $deleted = false,
			?array $ids = null,
			?string $uses = null,
			?array $views = null,
		): array {
			return [];
		}//end searchObjectsPaginated()

		/**
		 * Search objects by register and schema slug.
		 *
		 * @param string               $registerSlug  The register slug.
		 * @param string               $schemaSlug    The schema slug.
		 * @param array<string, mixed> $filters       Filters to apply.
		 * @param boolean              $_rbac         Whether to enforce RBAC checks.
		 * @param boolean              $_multitenancy Whether to enforce tenant scoping.
		 *
		 * @return array<int, mixed>|int
		 */
		public function searchObjectsBySlug(
			string $registerSlug,
			string $schemaSlug,
			array $filters = [],
			bool $_rbac = true,
			bool $_multitenancy = true,
		): array|int {
			return [];
		}//end searchObjectsBySlug()

		/**
		 * Clear the current register/schema selection.
		 *
		 * @return void
		 */
		public function clearCurrents(): void {
		}//end clearCurrents()

		/**
		 * Build a search query from request parameters.
		 *
		 * @param array<string, mixed>              $requestParams The request parameters.
		 * @param integer|string|array<int,mixed>|null $register   Register slug(s) or ID(s).
		 * @param integer|string|array<int,mixed>|null $schema     Schema slug(s) or ID(s).
		 * @param array<int, mixed>|null            $ids           Restrict to these ids.
		 *
		 * @return array<string, mixed>
		 */
		public function buildSearchQuery(
			array $requestParams,
			int|string|array|null $register = null,
			int|string|array|null $schema = null,
			?array $ids = null,
		): array {
			return [];
		}//end buildSearchQuery()

		/**
		 * Persist many objects at once.
		 *
		 * @param array<int, mixed> $objects         The objects to persist.
		 * @param string|int|null   $register        Register slug or ID.
		 * @param string|int|null   $schema          Schema slug or ID.
		 * @param boolean           $_rbac           Whether to enforce RBAC checks.
		 * @param boolean           $_multitenancy   Whether to enforce tenant scoping.
		 * @param boolean           $validation      Whether to validate.
		 * @param boolean           $events          Whether to emit events.
		 * @param boolean           $deduplicateIds  Whether to deduplicate ids.
		 * @param boolean           $enrich          Whether to enrich the results.
		 * @param boolean           $_audit          Whether to write an audit trail.
		 *
		 * @return array<int, mixed>
		 */
		public function saveObjects(
			array $objects,
			string|int|null $register = null,
			string|int|null $schema = null,
			bool $_rbac = true,
			bool $_multitenancy = true,
			bool $validation = false,
			bool $events = false,
			bool $deduplicateIds = true,
			bool $enrich = true,
			bool $_audit = true,
		): array {
			return [];
		}//end saveObjects()

		/**
		 * Run an operation in the system context.
		 *
		 * @param callable $operation The operation to run.
		 *
		 * @return mixed
		 */
		public function runAsSystem(callable $operation) {
			return $operation();
		}//end runAsSystem()

		/**
		 * Count objects matching the given configuration.
		 *
		 * @param array<string, mixed> $config The query configuration.
		 *
		 * @return integer
		 */
		public function count(array $config = []): int {
			return 0;
		}//end count()

		/**
		 * Release a lock on an object.
		 *
		 * @param string|int $identifier The object identifier.
		 * @param boolean    $advisory   Whether the lock is advisory.
		 *
		 * @return boolean
		 */
		public function unlockObject(string|int $identifier, bool $advisory = false): bool {
			return true;
		}//end unlockObject()

		/**
		 * Take a lock on an object.
		 *
		 * @param string       $identifier The object identifier.
		 * @param string|null  $process    The process taking the lock.
		 * @param integer|null $duration   Lock duration in seconds.
		 * @param boolean      $advisory   Whether the lock is advisory.
		 *
		 * @return array<string, mixed>
		 */
		public function lockObject(
			string $identifier,
			?string $process = null,
			?int $duration = null,
			bool $advisory = false,
		): array {
			return [];
		}//end lockObject()

		/**
		 * Delete many objects at once.
		 *
		 * @param array<int, string> $uuids         The object UUIDs.
		 * @param boolean            $_rbac         Whether to enforce RBAC checks.
		 * @param boolean            $_multitenancy Whether to enforce tenant scoping.
		 *
		 * @return array<int, mixed>
		 */
		public function deleteObjects(
			array $uuids = [],
			bool $_rbac = true,
			bool $_multitenancy = true,
		): array {
			return [];
		}//end deleteObjects()

		/**
		 * Return the audit log for an object.
		 *
		 * @param string               $uuid          The object UUID.
		 * @param array<string, mixed> $filters       Filters to apply.
		 * @param boolean              $_rbac         Whether to enforce RBAC checks.
		 * @param boolean              $_multitenancy Whether to enforce tenant scoping.
		 *
		 * @return array<int, mixed>
		 */
		public function getLogs(
			string $uuid,
			array $filters = [],
			bool $_rbac = true,
			bool $_multitenancy = true,
		): array {
			return [];
		}//end getLogs()

		/**
		 * Update an object.
		 *
		 * @param string               $objectId      The object id.
		 * @param array<string, mixed> $data          The new data.
		 * @param boolean              $_rbac         Whether to enforce RBAC checks.
		 * @param boolean              $_multitenancy Whether to enforce tenant scoping.
		 *
		 * @return ObjectEntityInterface
		 */
		public function updateObject(
			string $objectId,
			array $data,
			bool $_rbac = true,
			bool $_multitenancy = true,
		): ObjectEntityInterface {
			throw new LogicException(self::NOT_CONFIGURED);
		}//end updateObject()

		/**
		 * Merge a partial update onto an existing object.
		 *
		 * Added to the published contract in hydra-gates v1.8.1. v1.8.0 shipped
		 * the interface WITHOUT it, so this stub satisfied the contract by
		 * accident; taking v1.8.1 turns that into a load-time fatal —
		 *
		 *   Class OCA\OpenRegister\Service\ObjectService contains 1 abstract
		 *   method and must therefore be declared abstract
		 *
		 * — which kills the whole suite before a test runs, exactly as this
		 * file's header warns. `updateObject()` REPLACES; this is the merging
		 * path, and it is on the contract so callers stop reimplementing
		 * read-merge-write or silently erasing the fields they did not send.
		 *
		 * @param array<string, mixed> $data           Fields to merge onto the stored object.
		 * @param string|int|null      $register       Register the object belongs to.
		 * @param string|int|null      $schema         Schema the object belongs to.
		 * @param bool                 $_rbac          Whether to apply RBAC.
		 * @param bool                 $_multitenancy  Whether to apply multitenancy.
		 * @param IUser|null           $currentUser    Acting user.
		 *
		 * @return ObjectEntityInterface
		 */
		public function patchObject(
			string $objectId,
			array $data,
			string|int|null $register = null,
			string|int|null $schema = null,
			bool $_rbac = true,
			bool $_multitenancy = true,
			?IUser $currentUser = null,
		): ObjectEntityInterface {
			throw new LogicException(self::NOT_CONFIGURED);
		}//end patchObject()

		/**
		 * Return the objects this object uses.
		 *
		 * @param string               $objectId      The object id.
		 * @param array<string, mixed> $query         The query.
		 * @param boolean              $_rbac         Whether to enforce RBAC checks.
		 * @param boolean              $_multitenancy Whether to enforce tenant scoping.
		 *
		 * @return array<int, mixed>
		 */
		public function getObjectUses(
			string $objectId,
			array $query = [],
			bool $_rbac = true,
			bool $_multitenancy = true,
		): array {
			return [];
		}//end getObjectUses()

		/**
		 * Return the objects that use this object.
		 *
		 * @param string               $objectId      The object id.
		 * @param array<string, mixed> $query         The query.
		 * @param boolean              $_rbac         Whether to enforce RBAC checks.
		 * @param boolean              $_multitenancy Whether to enforce tenant scoping.
		 *
		 * @return array<int, mixed>
		 */
		public function getObjectUsedBy(
			string $objectId,
			array $query = [],
			bool $_rbac = true,
			bool $_multitenancy = true,
		): array {
			return [];
		}//end getObjectUsedBy()

		/**
		 * Find objects by their relations.
		 *
		 * @param string  $search       The search term.
		 * @param boolean $partialMatch Whether to match partially.
		 *
		 * @return array<int, mixed>
		 */
		public function findByRelations(string $search, bool $partialMatch = true): array {
			return [];
		}//end findByRelations()

		/**
		 * Find a single object without audit or rendering side-effects.
		 *
		 * @param string                    $id            The object id.
		 * @param array<string, mixed>|null $_extend       Properties to expand.
		 * @param boolean                   $files         Whether to include files.
		 * @param string|int|null           $register      Register slug or ID.
		 * @param string|int|null           $schema        Schema slug or ID.
		 * @param boolean                   $_rbac         Whether to enforce RBAC checks.
		 * @param boolean                   $_multitenancy Whether to enforce tenant scoping.
		 *
		 * @return ObjectEntityInterface
		 */
		public function findSilent(
			string $id,
			?array $_extend = [],
			bool $files = false,
			string|int|null $register = null,
			string|int|null $schema = null,
			bool $_rbac = true,
			bool $_multitenancy = true,
		): ObjectEntityInterface {
			throw new LogicException(self::NOT_CONFIGURED);
		}//end findSilent()

		/**
		 * Count the objects a search would return.
		 *
		 * @param array<string, mixed>   $query         The search query.
		 * @param boolean                $_rbac         Whether to enforce RBAC checks.
		 * @param boolean                $_multitenancy Whether to enforce tenant scoping.
		 * @param array<int, mixed>|null $ids           Restrict to these ids.
		 * @param string|null            $uses          Restrict to objects using this one.
		 *
		 * @return integer
		 */
		public function countSearchObjects(
			array $query = [],
			bool $_rbac = true,
			bool $_multitenancy = true,
			?array $ids = null,
			?string $uses = null,
		): int {
			return 0;
		}//end countSearchObjects()

		/**
		 * Return the currently selected object.
		 *
		 * @return ObjectEntityInterface|null
		 */
		public function getObject(): ?ObjectEntityInterface {
			return null;
		}//end getObject()
	}//end class
}//end if
