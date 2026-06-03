<?php

/**
 * Pipelinq CtiPayloadTrait.
 *
 * Shared payload-coercion helper for CTI adapters.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Cti\Adapter
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Cti\Adapter;

/**
 * Coercion helpers shared by the platform adapters.
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.1
 */
trait CtiPayloadTrait
{
    /**
     * Coerce a webhook value to a non-empty string, or null.
     *
     * @param mixed $value The value to coerce.
     *
     * @return string|null The string, or null when not coercible.
     */
    private function stringOrNull(mixed $value): ?string
    {
        if (is_string($value) === true && $value !== '') {
            return $value;
        }

        if (is_int($value) === true || is_float($value) === true) {
            return (string) $value;
        }

        return null;
    }//end stringOrNull()
}//end trait
