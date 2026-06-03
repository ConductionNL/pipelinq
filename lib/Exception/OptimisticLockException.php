<?php

/**
 * Pipelinq OptimisticLockException.
 *
 * Raised when a PATCH against a ZRC/DRC resource returns HTTP 412 Precondition
 * Failed because the cached ETag is stale. Carries the local pre-image and the
 * fresh server representation (fetched via a follow-up GET) plus the conflicting
 * field so the caller can reconcile. The bridge never auto-retries the PATCH
 * (REQ-ZGW-009).
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
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#REQ-ZGW-009
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Exception;

/**
 * Raised on an HTTP 412 optimistic-concurrency conflict.
 *
 * @spec openspec/changes/zgw-api-bridge/tasks.md#8.3
 */
class OptimisticLockException extends ZgwBridgeException
{
    /**
     * Constructor.
     *
     * @param string               $message             Human-readable description of the conflict.
     * @param array<string, mixed> $staleRepresentation The local pre-image sent in the PATCH.
     * @param array<string, mixed> $freshRepresentation The current server representation.
     * @param string               $conflictingField    The field name in contention (best effort).
     */
    public function __construct(
        string $message,
        private array $staleRepresentation,
        private array $freshRepresentation,
        private string $conflictingField,
    ) {
        parent::__construct(message: $message);
    }//end __construct()

    /**
     * Get the stale local pre-image.
     *
     * @return array<string, mixed> The representation sent in the failed PATCH.
     */
    public function getStaleRepresentation(): array
    {
        return $this->staleRepresentation;
    }//end getStaleRepresentation()

    /**
     * Get the fresh server representation.
     *
     * @return array<string, mixed> The current server-side representation.
     */
    public function getFreshRepresentation(): array
    {
        return $this->freshRepresentation;
    }//end getFreshRepresentation()

    /**
     * Get the conflicting field name.
     *
     * @return string The field in contention.
     */
    public function getConflictingField(): string
    {
        return $this->conflictingField;
    }//end getConflictingField()
}//end class
