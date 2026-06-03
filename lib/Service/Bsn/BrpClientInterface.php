<?php

/**
 * Pipelinq BrpClientInterface.
 *
 * Abstraction over a BRP (Basisregistratie Personen) person lookup. The app
 * depends only on this interface so the concrete HaalCentraal REST client can be
 * swapped for a fake in tests (no live RvIG / Haal-Centraal credential is ever
 * required to build or test) and replaced by an OpenConnector-backed Source
 * later without touching call sites (ADR-019).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Bsn
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Bsn;

/**
 * Contract for a BRP person lookup (REQ-BSN-003).
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.2
 */
interface BrpClientInterface
{
    /**
     * Look up a person in the BRP by BSN.
     *
     * Implementations MUST treat the BSN as special-category data: it is never
     * logged or placed in a URL path, and never echoed in a thrown message.
     *
     * @param string $bsn The 9-digit BSN to look up (raw; never logged).
     *
     * @return BrpPersoon|null The normalised person, or null when the BSN is not
     *                         found in the BRP (a clean negative, not an error).
     *
     * @throws HaalCentraalException When the lookup fails (auth, transport,
     *                               upstream 5xx, timeout, malformed response).
     *
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.2
     */
    public function lookupPersoon(string $bsn): ?BrpPersoon;

    /**
     * Whether the client is fully configured (credentials + certificate present).
     *
     * Lets callers fail with a clear configuration error instead of attempting an
     * unauthenticated request.
     *
     * @return bool True when a lookup can be attempted.
     *
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.2
     */
    public function isConfigured(): bool;
}//end interface
