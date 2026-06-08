<?php

/**
 * Pipelinq PayloadTooLargeException.
 *
 * Raised when the sum of attached document sizes exceeds the configured
 * pre-base64 payload ceiling (default 25 MiB).
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
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-006
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Stuf;

/**
 * Pre-send domain error: payload too large for StUF envelope.
 */
class PayloadTooLargeException extends StufException
{
}//end class
