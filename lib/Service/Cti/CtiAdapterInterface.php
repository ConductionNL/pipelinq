<?php

/**
 * Pipelinq CtiAdapterInterface.
 *
 * Interface implemented by every CTI adapter (CallVoip, RingCentral, Asterisk, ...).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Cti
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
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

use OCA\Pipelinq\Service\Cti\Result\CtiCallResult;
use OCA\Pipelinq\Service\Cti\Result\CtiWebhookResult;

/**
 * Contract for platform-specific CTI adapters.
 *
 * Adapters translate vendor-specific webhook payloads into a normalised
 * {@see CtiWebhookResult}, originate outbound calls and verify webhook
 * signatures. Adapters MUST be stateless beyond the configuration they hold.
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.1
 */
interface CtiAdapterInterface {
	/**
	 * Identifier of this platform (callvoip|ringcentral|asterisk|other).
	 *
	 * @return string Platform identifier.
	 */
	public function getPlatform(): string;

	/**
	 * Parse and normalise an inbound webhook payload.
	 *
	 * @param array<string,mixed> $payload Raw decoded webhook body.
	 *
	 * @return CtiWebhookResult Normalised event.
	 */
	public function handleInboundWebhook(array $payload): CtiWebhookResult;

	/**
	 * Originate an outbound call.
	 *
	 * @param string $extension The agent's telephony extension that should ring.
	 * @param string $targetNumber Destination phone number (E.164 preferred).
	 * @param string $callerId Caller-ID to present on the outbound call.
	 *
	 * @return CtiCallResult Outcome including the new external call id.
	 */
	public function originateCall(string $extension, string $targetNumber, string $callerId): CtiCallResult;

	/**
	 * Verify a webhook payload signature.
	 *
	 * The $signature is whatever the platform supplies: an HMAC-SHA256 hex digest,
	 * an OAuth bearer token, or a shared-secret query parameter.
	 *
	 * @param string $payload Raw request body as received.
	 * @param string $signature Signature/token to validate.
	 *
	 * @return bool True when the signature is valid.
	 */
	public function verifyWebhookSignature(string $payload, string $signature): bool;
}//end interface
