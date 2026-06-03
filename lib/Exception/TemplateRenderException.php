<?php

/**
 * Pipelinq TemplateRenderException.
 *
 * Thrown when a Berichtenbox message template fails to render or validate.
 *
 * @category Exception
 * @package  OCA\Pipelinq\Exception
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Exception;

/**
 * Thrown when template rendering or XHTML validation fails.
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#REQ-OUTBOUND-001
 */
class TemplateRenderException extends BerichtenboxException
{
}//end class
