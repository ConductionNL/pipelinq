<?php

/**
 * Pipelinq BesluittypeNotInCatalogusException.
 *
 * Raised when a besluittype cannot be resolved by omschrijving from the ZTC
 * (catalogi) before a createBesluit call (REQ-ZGW-004, REQ-ZGW-005).
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
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#REQ-ZGW-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Exception;

/**
 * Raised when a besluittype is not present in the catalogus.
 *
 * @spec openspec/changes/zgw-api-bridge/tasks.md#8.3
 */
class BesluittypeNotInCatalogusException extends ZgwBridgeException
{
    /**
     * Constructor.
     *
     * @param string $omschrijving The besluittype omschrijving that could not be resolved.
     */
    public function __construct(private string $omschrijving)
    {
        parent::__construct(
            message: sprintf('Besluittype "%s" niet gevonden in de catalogus (ZTC).', $omschrijving)
        );
    }//end __construct()

    /**
     * Get the unresolved besluittype omschrijving.
     *
     * @return string The omschrijving.
     */
    public function getOmschrijving(): string
    {
        return $this->omschrijving;
    }//end getOmschrijving()
}//end class
