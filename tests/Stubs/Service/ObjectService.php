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
 * Only a minimal surface is declared — the real ObjectService has more.
 * PHPUnit's createMock() generates a full mock regardless; the declarations
 * here only exist to satisfy the type system.
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

if (class_exists(ObjectService::class) === false) {
    /**
     * Stub class for ObjectService — used only in standalone unit tests.
     *
     * Replaced by the real implementation when the openregister app is installed.
     */
    class ObjectService
    {
        /**
         * Find a single object by ID.
         *
         * @param string $id       The object UUID.
         * @param string $register Register slug or ID.
         * @param string $schema   Schema slug or ID.
         *
         * @return array<string, mixed>|object|null
         */
        public function find(string $id, string $register='', string $schema=''): array|object|null
        {
            return null;
        }//end find()

        /**
         * Find all objects matching the given configuration.
         *
         * Mirrors the real OR ObjectService signature so test mocks that call
         * `findAll(config: ['filters' => ...])` (the documented form used by
         * pipelinq services) do not blow up with "Unknown named parameter".
         *
         * `$_rbac` / `$_multitenancy` are the system-context escape hatches used
         * by repair steps, CLI commands and cron: those run with no user session,
         * so an RBAC-scoped read resolves the actor to 'Anonymous' and returns
         * nothing. They are part of the real signature and must be declared here,
         * or a caller passing them by name throws "Unknown named parameter".
         *
         * @param array<string, mixed> $config        Configuration with `filters`, `sort`, etc.
         * @param bool                 $_rbac         Whether to enforce RBAC scoping.
         * @param bool                 $_multitenancy Whether to enforce tenant scoping.
         *
         * @return array<int, mixed>
         */
        public function findAll(array $config=[], bool $_rbac=true, bool $_multitenancy=true): array
        {
            return [];
        }//end findAll()

        /**
         * Count objects matching the given configuration.
         *
         * Mirrors the real OR ObjectService signature so test mocks that call
         * `count(config: ['filters' => ...])` (the form used by pipelinq query
         * pushdown) do not blow up with "Unknown named parameter".
         *
         * @param array<string, mixed> $config Configuration with `filters`, etc.
         *
         * @return int
         */
        public function count(array $config=[]): int
        {
            return 0;
        }//end count()

        /**
         * Save (create or update) an object.
         *
         * Mirrors the real OR ObjectService signature (parameter is `$object`,
         * not `$objectOrArray`) so test mocks that use the named-arg form
         * (`saveObject(object: ..., register: ..., schema: ..., uuid: ...)`)
         * do not blow up with "Unknown named parameter".
         *
         * @param array<string, mixed>|object $object        The data to persist.
         * @param array<string, mixed>|null   $extend        Additional field values.
         * @param string|int|null             $register      Register slug or ID.
         * @param string|int|null             $schema        Schema slug or ID.
         * @param string|null                 $uuid          UUID for update; null for create.
         * @param bool                        $_rbac         Whether to enforce RBAC checks.
         * @param bool                        $_multitenancy Whether to enforce tenant scoping.
         * @param bool                        $silent        Whether to suppress side-effects.
         * @param array<string, mixed>|null   $uploadedFiles Files to attach.
         * @param object|null                 $currentUser   Acting user for folder access.
         *
         * @return array<string, mixed>|object
         */
        public function saveObject(
            array|object $object,
            ?array $extend=[],
            string|int|null $register=null,
            string|int|null $schema=null,
            ?string $uuid=null,
            bool $_rbac=true,
            bool $_multitenancy=true,
            bool $silent=false,
            ?array $uploadedFiles=null,
            ?object $currentUser=null,
        ): array|object {
            return [];
        }//end saveObject()

        /**
         * Set the active register for subsequent calls (fluent API).
         *
         * @param string|int $register Register slug or ID.
         *
         * @return static
         */
        public function setRegister(string|int $register): static
        {
            return $this;
        }//end setRegister()

        /**
         * Set the active schema for subsequent calls (fluent API).
         *
         * @param string|int $schema Schema slug or ID.
         *
         * @return static
         */
        public function setSchema(string|int $schema): static
        {
            return $this;
        }//end setSchema()

        /**
         * Delete an object by UUID.
         *
         * @param string      $uuid     The object UUID.
         * @param string|null $register Register slug or ID (optional, overrides setRegister).
         * @param string|null $schema   Schema slug or ID (optional, overrides setSchema).
         *
         * @return bool
         */
        public function deleteObject(string $uuid, string|int|null $register=null, string|int|null $schema=null): bool
        {
            return true;
        }//end deleteObject()
    }//end class
}//end if
