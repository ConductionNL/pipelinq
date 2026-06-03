<?php

/**
 * Pipelinq DoubleWritePathException.
 *
 * Raised by the coexistence validator when both a StUF endpoint and a ZGW
 * endpoint are configured as the active write path for the same gemeente, which
 * would cause duplicate zaak registration. Raised before any external call so
 * the beheerder can disable one write path (REQ-ZGW-008).
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
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#REQ-ZGW-008
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Exception;

/**
 * Raised when both StUF and ZGW write paths are enabled for one gemeente.
 *
 * @spec openspec/changes/zgw-api-bridge/tasks.md#8.3
 */
class DoubleWritePathException extends ZgwBridgeException
{
    /**
     * Constructor.
     *
     * @param string        $message              Human-readable instruction to the beheerder.
     * @param string        $gemeenteCode         The gemeente code in conflict.
     * @param array<string> $conflictingEndpoints Identifiers of the conflicting write endpoints.
     */
    public function __construct(
        string $message,
        private string $gemeenteCode='',
        private array $conflictingEndpoints=[],
    ) {
        parent::__construct(message: $message);
    }//end __construct()

    /**
     * Get the gemeente code in conflict.
     *
     * @return string The gemeente code.
     */
    public function getGemeenteCode(): string
    {
        return $this->gemeenteCode;
    }//end getGemeenteCode()

    /**
     * Get the identifiers of the conflicting write endpoints.
     *
     * @return array<string> The endpoint identifiers.
     */
    public function getConflictingEndpoints(): array
    {
        return $this->conflictingEndpoints;
    }//end getConflictingEndpoints()
}//end class
