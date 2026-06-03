<?php

/**
 * Pipelinq CryptoException.
 *
 * Thrown when BSN encryption, decryption, or hashing fails.
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
 * Thrown when a cryptographic operation on a BSN fails.
 *
 * The message MUST NOT contain plaintext BSN material.
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#REQ-SECURITY-009
 */
class CryptoException extends BerichtenboxException
{
}//end class
