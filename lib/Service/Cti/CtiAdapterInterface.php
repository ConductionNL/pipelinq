<?php

/**
 * Pipelinq CtiAdapterInterface.
 *
 * Platform-agnostic contract every telephony (CTI) adapter implements.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Cti
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Cti;

/**
 * Contract for platform-specific CTI adapters.
 *
 * Adding a new telephony vendor requires only a new class implementing this
 * interface and a registration in {@see AdapterRegistry}; no changes to the
 * platform-neutral CtiService or CtiController are needed (REQ-CTI-006).
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.1
 */
interface CtiAdapterInterface
{
    /**
     * The platform identifier this adapter handles (e.g. "callvoip").
     *
     * @return string The lowercase platform slug.
     */
    public function getPlatform(): string;

    /**
     * Verify an inbound webhook is authentic before any processing.
     *
     * Implementations MUST perform a constant-time comparison and MUST NOT
     * trust the request until this returns true (ADR-005).
     *
     * @param string                $rawBody The exact raw request body bytes.
     * @param array<string, string> $headers Lower-cased request headers.
     * @param array<string, string> $query   Query parameters.
     * @param string                $secret  The configured shared secret / token.
     *
     * @return bool True when the signature/token is valid.
     */
    public function verifyWebhookSignature(string $rawBody, array $headers, array $query, string $secret): bool;

    /**
     * Translate a vendor webhook payload into the platform-neutral result.
     *
     * @param array<string, mixed> $payload The decoded webhook body.
     *
     * @return CtiWebhookResult The normalised event.
     */
    public function handleInboundWebhook(array $payload): CtiWebhookResult;

    /**
     * Originate an outbound call: ring the agent extension, then dial the target.
     *
     * @param string $extension    The agent's extension.
     * @param string $targetNumber The number to dial (E.164).
     * @param string $callerId     The caller ID to present.
     *
     * @return CtiCallResult The origination outcome.
     */
    public function originateCall(string $extension, string $targetNumber, string $callerId): CtiCallResult;
}//end interface
