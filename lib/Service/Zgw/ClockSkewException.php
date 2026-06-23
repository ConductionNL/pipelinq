<?php

/**
 * Pipelinq ClockSkewException.
 *
 * Raised when a ZGW component (ZRC/DRC/BRC/ZTC/AC) rejects an inbound JWT
 * because the pipelinq host clock has drifted outside the VNG-API-Common
 * ±60 s leeway window. Carries both the locally-observed `iat` timestamp
 * and the server-reported clock value (extracted from the 403 VNG fault
 * payload when present) so operations can correlate the drift without
 * re-issuing the call.
 *
 * Per REQ-ZGW-001 the bridge MUST NOT auto-retry on this error.
 *
 * @category Exception
 * @package  OCA\Pipelinq\Service\Zgw
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Zgw;

/**
 * Clock-skew (VNG "JWT verlopen" / "JWT nog niet geldig") error.
 */
class ClockSkewException extends ZgwException
{
    /**
     * Constructor.
     *
     * @param string $message      Human-readable error message.
     * @param int    $observedTime Locally-observed Unix time (the iat we sent).
     * @param int    $serverTime   Server-reported clock value, or 0 if unknown.
     */
    public function __construct(
        string $message,
        public readonly int $observedTime,
        public readonly int $serverTime=0,
    ) {
        parent::__construct(message: $message);
    }//end __construct()
}//end class
