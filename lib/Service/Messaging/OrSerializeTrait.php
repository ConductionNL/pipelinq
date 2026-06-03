<?php

/**
 * Pipelinq OrSerializeTrait.
 *
 * Shared helper that serialises an OpenRegister result (entity or array) to a
 * plain associative array.
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
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-0.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Messaging;

/**
 * Serialises an OpenRegister result to a plain array.
 *
 * OpenRegister's find/findAll may yield either entity objects (with a
 * `jsonSerialize()`) or plain arrays; this normalises both to an array so the
 * messaging services can read fields uniformly (ADR-022).
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-0.2
 */
trait OrSerializeTrait
{
    /**
     * Serialise an OpenRegister result (entity or array) to a plain array.
     *
     * @param mixed $result The raw result.
     *
     * @return array<string, mixed> The serialised object.
     */
    private function serialize(mixed $result): array
    {
        if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {
            $serialized = $result->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }

            return [];
        }

        if (is_array($result) === true) {
            return $result;
        }

        return [];
    }//end serialize()
}//end trait
