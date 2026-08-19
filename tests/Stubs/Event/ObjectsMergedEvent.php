<?php

/**
 * Test stub for OpenRegister's ObjectsMergedEvent.
 *
 * Mirrors the read surface (getSurvivorUuid / getMergedFromUuids /
 * getMergeOperationId / isReversal) the pipelinq ObjectsMergedSyncListener
 * consumes; OpenRegister is not a test-time dependency. Resolved via the
 * `OCA\OpenRegister\ => tests/Stubs/` autoload-dev mapping.
 *
 * @category Test
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCP\EventDispatcher\Event;

/**
 * Minimal ObjectsMergedEvent stub.
 */
class ObjectsMergedEvent extends Event
{
    /**
     * Constructor.
     *
     * @param string             $survivorUuid     Surviving object uuid.
     * @param array<int, string> $mergedFromUuids  Merged-away object uuids.
     * @param string             $mergeOperationId Persisted mergeOperation uuid.
     * @param bool               $isReversal       True for a reversal, not a merge.
     */
    public function __construct(
        private string $survivorUuid,
        private array $mergedFromUuids,
        private string $mergeOperationId,
        private bool $isReversal=false,
    ) {
        parent::__construct();
    }//end __construct()

    /**
     * The surviving object's uuid.
     *
     * @return string Survivor uuid.
     */
    public function getSurvivorUuid(): string
    {
        return $this->survivorUuid;
    }//end getSurvivorUuid()

    /**
     * The merged-away object uuids.
     *
     * @return array<int, string> Merged-from uuids.
     */
    public function getMergedFromUuids(): array
    {
        return $this->mergedFromUuids;
    }//end getMergedFromUuids()

    /**
     * The persisted mergeOperation uuid.
     *
     * @return string Merge-operation uuid.
     */
    public function getMergeOperationId(): string
    {
        return $this->mergeOperationId;
    }//end getMergeOperationId()

    /**
     * Whether this event represents a reversal.
     *
     * @return bool True when reversal.
     */
    public function isReversal(): bool
    {
        return $this->isReversal;
    }//end isReversal()
}//end class
