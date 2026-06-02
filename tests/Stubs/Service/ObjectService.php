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
 * Only the methods used by PublicSurveyController are declared — the real
 * ObjectService has more. PHPUnit's createMock() generates a full mock
 * regardless; the declarations here only exist to satisfy the type system.
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
        public function find(string $id, string $register = '', string $schema = ''): array|object|null
        {
            return null;
        }//end find()

        /**
         * Find all objects matching the given filters.
         *
         * @param array<string, mixed> $filters Search filters and options.
         *
         * @return array<string, mixed>
         */
        public function findAll(array $filters = []): array
        {
            return [];
        }//end findAll()

        /**
         * Save (create or update) an object.
         *
         * @param array<string, mixed>|object $objectOrArray The data to persist.
         * @param array<string, mixed>        $extend        Additional field values.
         * @param string                      $register      Register slug or ID.
         * @param string                      $schema        Schema slug or ID.
         * @param string|null                 $uuid          UUID for update; null for create.
         *
         * @return array<string, mixed>|object
         */
        public function saveObject(
            array|object $objectOrArray,
            array $extend = [],
            string $register = '',
            string $schema = '',
            ?string $uuid = null,
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
