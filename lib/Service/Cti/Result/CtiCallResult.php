<?php

/**
 * Pipelinq CtiCallResult.
 *
 * Value object returned by adapter::originateCall.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Cti\Result
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Cti\Result;

/**
 * Outcome of an outbound click-to-dial originate request.
 */
final class CtiCallResult
{
    /**
     * Constructor.
     *
     * @param bool        $success        Whether the originate request was accepted.
     * @param string|null $externalCallId Platform's call UUID when allocated.
     * @param string|null $error          Error message when $success is false.
     * @param string|null $platform       Adapter platform identifier.
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?string $externalCallId=null,
        public readonly ?string $error=null,
        public readonly ?string $platform=null,
    ) {
    }//end __construct()
}//end class
