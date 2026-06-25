<?php

/**
 * Test stub for OCA\OpenRegister\Db\ObjectEntity.
 *
 * Provides the method signatures that the unit tests mock on the entity
 * returned by ObjectService. Resolved via the `OCA\OpenRegister\ => tests/Stubs/`
 * autoload-dev mapping when the real OpenRegister app is not installed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Stub for ObjectEntity with the methods used by Pipelinq tests.
 *
 * Declared CONCRETE (not abstract) with trivial method bodies so the stub is a
 * safe stand-in even when a real OpenRegister class that `extends ObjectEntity`
 * (e.g. Service\Notification\SystemEntityObjectAdapter) is eagerly loaded by
 * Nextcloud in the same process: an abstract stub would make every such subclass
 * "contains abstract methods and must be declared abstract" and fatal. The
 * methods are real declared methods (not magic getters) so unit tests can mock
 * them with `onlyMethods(['getSchema', ...])`; PHPUnit's createMock() overrides
 * the bodies anyway.
 */
class ObjectEntity
{

    /**
     * Return the raw object data as an array.
     *
     * @return array<string,mixed>
     */
    public function getObject(): array
    {
        return [];

    }//end getObject()


    /**
     * Return the object UUID.
     *
     * @return string
     */
    public function getUuid(): string
    {
        return '';

    }//end getUuid()


    /**
     * Return the schema id/slug the object belongs to.
     *
     * @return string|null
     */
    public function getSchema(): ?string
    {
        return null;

    }//end getSchema()


    /**
     * Return a JSON-serializable representation of the entity.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [];

    }//end jsonSerialize()

}//end class
