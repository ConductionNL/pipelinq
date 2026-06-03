<?php

/**
 * Pipelinq MessagingPayloadTrait.
 *
 * Shared payload-coercion helpers for messaging provider clients.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Messaging
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.5
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Messaging;

/**
 * Coercion helpers shared by the provider clients.
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.5
 */
trait MessagingPayloadTrait
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

    /**
     * Coerce a value to a float, or null.
     *
     * @param mixed $value The value to coerce.
     *
     * @return float|null The float, or null when not numeric.
     */
    private function floatOrNull(mixed $value): ?float
    {
        if (is_int($value) === true || is_float($value) === true) {
            return (float) $value;
        }

        if (is_string($value) === true && is_numeric($value) === true) {
            return (float) $value;
        }

        return null;
    }//end floatOrNull()

    /**
     * Classify an HTTP status code as a transient (retryable) failure.
     *
     * @param int $statusCode The HTTP status code.
     *
     * @return bool True for 5xx and 429 (retryable); false otherwise.
     */
    private function isTransientStatus(int $statusCode): bool
    {
        return $statusCode >= 500 || $statusCode === 429;
    }//end isTransientStatus()
}//end trait
