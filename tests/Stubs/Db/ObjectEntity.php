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
 */
abstract class ObjectEntity
{

    /**
     * Return the raw object data as an array.
     *
     * @return array<string,mixed>
     */
    abstract public function getObject(): array;

    /**
     * Return the object UUID.
     *
     * @return string
     */
    abstract public function getUuid(): string;

    /**
     * Return a JSON-serializable representation of the entity.
     *
     * @return array<string,mixed>
     */
    abstract public function jsonSerialize(): array;

}//end class
