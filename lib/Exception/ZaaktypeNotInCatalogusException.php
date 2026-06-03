<?php

/**
 * Pipelinq ZaaktypeNotInCatalogusException.
 *
 * Raised when a zaaktype cannot be resolved by omschrijving from the ZTC
 * (catalogi) before a createZaak call (REQ-ZGW-005).
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
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#REQ-ZGW-005
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Exception;

/**
 * Raised when a zaaktype is not present in the catalogus.
 *
 * @spec openspec/changes/zgw-api-bridge/tasks.md#8.3
 */
class ZaaktypeNotInCatalogusException extends ZgwBridgeException
{
    /**
     * Constructor.
     *
     * @param string $omschrijving The zaaktype omschrijving that could not be resolved.
     */
    public function __construct(private string $omschrijving)
    {
        parent::__construct(
            message: sprintf('Zaaktype "%s" niet gevonden in de catalogus (ZTC).', $omschrijving)
        );
    }//end __construct()

    /**
     * Get the unresolved zaaktype omschrijving.
     *
     * @return string The omschrijving.
     */
    public function getOmschrijving(): string
    {
        return $this->omschrijving;
    }//end getOmschrijving()
}//end class
