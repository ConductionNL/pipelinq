<?php

/**
 * Pipelinq CtiCallResult.
 *
 * Immutable value object describing the outcome of an outbound call
 * origination request to a telephony platform.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Cti
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

namespace OCA\Pipelinq\Service\Cti;

/**
 * Result of an originateCall() request.
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.1
 */
class CtiCallResult
{
    /**
     * Constructor.
     *
     * @param bool        $success        Whether the platform accepted the originate request.
     * @param string|null $externalCallId The platform call identifier assigned to the new call.
     * @param string|null $message        Human-readable status or error message.
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?string $externalCallId=null,
        public readonly ?string $message=null,
    ) {
    }//end __construct()
}//end class
