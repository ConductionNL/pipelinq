<?php

/**
 * Pipelinq StUF TimeoutException.
 *
 * Raised when a synchronous Lv01 vraag does not receive a La01 antwoord
 * within the configured timeout (default 30 seconds).
 *
 * @category Exception
 * @package  OCA\Pipelinq\Service\Stuf
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Stuf;

/**
 * Synchronous vraag/antwoord exceeded the configured timeout.
 */
class TimeoutException extends StufException
{
}//end class
