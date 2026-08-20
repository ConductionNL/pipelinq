<?php

/**
 * Pipelinq TenderTypeNotFoundException.
 *
 * Raised by PosTenderService::getTenderTypeByCode() / ::getTenderTypeById()
 * when the requested posTenderType does not exist or has been deactivated.
 * Controllers map this to HTTP 404 Not Found.
 *
 * @category Exception
 * @package  OCA\Pipelinq\Service
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
 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use Exception;

/**
 * Raised when a posTenderType lookup fails.
 *
 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-001
 */
class TenderTypeNotFoundException extends Exception {
}//end class
