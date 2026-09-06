<?php

/**
 * Pipelinq SmsProviderClientInterface.
 *
 * Common contract implemented by every concrete SMS provider client
 * (Twilio, MessageBird, CM.com, ...). The {@see SmsAdapter} talks to
 * this interface so failover and provider-hint logic stays free of
 * vendor SDK leaks.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Provider
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#3.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Provider;

/**
 * SmsProviderClientInterface — vendor-neutral SMS contract.
 *
 * Implementations MUST throw a {@see TransientSmsProviderException} on
 * 5xx / network / timeout failures (so SmsAdapter can fail over to
 * the next priority); any other exception (or the
 * {@see PermanentSmsProviderException}) is treated as permanent.
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#3.2
 */
interface SmsProviderClientInterface {
	/**
	 * Send a single SMS.
	 *
	 * @param string $toNumber Recipient phone number in E.164.
	 * @param string $body Plain-text body.
	 *
	 * @return array{externalMessageId: string, vendor: string} Provider
	 *                                                          message id and the vendor key that produced it.
	 *
	 * @spec openspec/specs/outbound-messaging/spec.md#REQ-OM-004
	 */
	public function send(string $toNumber, string $body): array;

	/**
	 * Verify the HMAC signature of an inbound webhook body.
	 *
	 * @param string $rawBody Raw request body.
	 * @param string $signature Signature header value (provider-specific).
	 *
	 * @return bool True when the signature matches the configured secret.
	 * @spec openspec/specs/outbound-messaging/spec.md#REQ-OM-004
	 */
	public function verifySignature(string $rawBody, string $signature): bool;

	/**
	 * The vendor key for this client (twilio, messagebird, cm-com, ...).
	 *
	 * @return string Vendor key.
	 * @spec openspec/specs/outbound-messaging/spec.md#REQ-OM-004
	 */
	public function getVendor(): string;
}//end interface
