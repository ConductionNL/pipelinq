<?php

/**
 * Pipelinq ClockSkewException.
 *
 * Raised when a ZGW component rejects a minted JWT for timing reasons ("JWT
 * verlopen" / "JWT nog niet geldig", HTTP 403). Carries the locally observed
 * timestamp and the server timestamp so a beheerder can correct NTP drift. The
 * bridge never auto-retries on this exception (REQ-ZGW-001).
 *
 * @category Exception
 * @package  OCA\Pipelinq\Exception
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#REQ-ZGW-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Exception;

/**
 * Raised on a JWT clock-skew rejection by a ZGW component.
 *
 * @spec openspec/changes/zgw-api-bridge/tasks.md#8.3
 */
class ClockSkewException extends ZgwBridgeException
{
    /**
     * Constructor.
     *
     * @param string $message    Human-readable description including both timestamps.
     * @param int    $observedAt Unix timestamp observed on the pipelinq host at mint time.
     * @param int    $serverAt   Unix timestamp reported (or inferred) for the ZGW host.
     */
    public function __construct(
        string $message,
        private int $observedAt,
        private int $serverAt,
    ) {
        parent::__construct(message: $message);
    }//end __construct()

    /**
     * Get the pipelinq-host timestamp observed when the JWT was minted.
     *
     * @return int The Unix timestamp.
     */
    public function getObservedAt(): int
    {
        return $this->observedAt;
    }//end getObservedAt()

    /**
     * Get the ZGW-host timestamp.
     *
     * @return int The Unix timestamp.
     */
    public function getServerAt(): int
    {
        return $this->serverAt;
    }//end getServerAt()
}//end class
