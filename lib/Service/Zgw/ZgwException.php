<?php

/**
 * Pipelinq ZgwException.
 *
 * Base class for all ZGW API bridge domain exceptions (clock-skew, missing
 * scope, ETag conflict, resource-not-found, catalogus lookup failure,
 * double-write-path conflict and NRC registration failure).
 *
 * Carrying a common base lets callers (Controllers, the Request creation
 * pipeline, integration tests) catch the whole family with a single
 * `try { … } catch (ZgwException $e) { … }` while still distinguishing
 * specific subclasses for error mapping.
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
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Zgw;

use RuntimeException;

/**
 * Base class for ZGW API bridge errors.
 */
class ZgwException extends RuntimeException
{
}//end class
