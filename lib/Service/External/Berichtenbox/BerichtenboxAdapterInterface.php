<?php

/**
 * Pipelinq Berichtenbox / Logius BBK 1.7 adapter port.
 *
 * The Berichtenbox is the Logius-operated message exchange for
 * burger-overheid correspondence: every Dutch citizen has a
 * Berichtenbox-mailbox they receive officiële post in through
 * MijnOverheid. Pipelinq's `burgerportaal-mijnoverheid-bridge`
 * capability dispatches outbound messages (beslissingsbrieven,
 * herinneringen, vooraankondigingen) into the Berichtenbox via
 * the BBK (Berichtenbox-koppelvlak) 1.7 REST API and receives
 * delivery-confirmation webhooks from Logius.
 *
 * Pipelinq already ships a working `LogiusConnector` HTTP client
 * for BBK 1.7 (oauth client-credentials + PKIoverheid signing +
 * inbound HMAC verification). This adapter port is the swap-in
 * seam so the dispatch orchestrator
 * (`BerichtenboxService::dispatchMessage()`,
 * `DispatchQueuedMessagesJob`, `EmailFallbackSender` fallback path)
 * targets a stable port rather than the concrete class, mirroring
 * the dormant-adapter pattern established by the wave-3 external
 * surface.
 *
 * The port is intentionally narrow — `dispatchMessage()` +
 * `verifyDeliveryWebhook()` + `checkMailbox()` returning structured
 * results — so the production binding (the existing
 * LogiusConnector-backed implementation OR a future
 * openconnector-routed binding) can be swapped in via
 * `Application::register()` without touching the orchestrator.
 *
 * Until activated, the default binding is dormant: it logs the
 * intent and returns a synthetic `DISPATCH_DEFERRED` outcome so
 * the surrounding lifecycle (FallbackEmailJob threshold,
 * `BerichtenboxWebhookController` delivery-receipt persistence)
 * stays observable in test + staging environments without
 * contacting Logius.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\External\Berichtenbox
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 * @link https://www.logius.nl/diensten/berichtenbox
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\External\Berichtenbox;

/**
 * Berichtenbox / Logius BBK 1.7 adapter port.
 *
 * Implementations MUST be side-effect-free when the dormant flag is
 * set; a dormant adapter records the intent (logger, audit trail)
 * and returns a synthetic DISPATCH_DEFERRED / VERIFY_DEFERRED /
 * MAILBOX_DEFERRED outcome so the surrounding lifecycle can advance
 * into `awaiting-berichtenbox` without contacting Logius.
 *
 * Activation steps for a real Berichtenbox binding:
 *  1. Provision a Logius BBK 1.7 client (OAuth 2.0 client-credentials
 *     token issuer + per-tenant PKIoverheid Services-server cert).
 *  2. Register the inbound webhook URL
 *     (`/apps/pipelinq/api/berichtenbox/webhook`) with Logius +
 *     load the webhook HMAC secret.
 *  3. Override the BerichtenboxAdapterInterface DI binding in
 *     `Application::register()` to the LogiusConnector-backed
 *     implementation (the existing `LogiusConnector` class) or to
 *     an openconnector-routed binding if the tenant centralises
 *     credentials there.
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md
 */
interface BerichtenboxAdapterInterface {
	/**
	 * Dispatch a Berichtenbox message envelope to Logius.
	 *
	 * @param array<string,mixed> $message BBK 1.7-shaped envelope —
	 *                                     conversationId, sender,
	 *                                     recipientBsn, subject, body,
	 *                                     attachments[], priority,
	 *                                     correlationId.
	 *
	 * @return BerichtenboxResult The dispatch outcome (status +
	 *                            Logius-side kenmerk).
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function dispatchMessage(array $message): BerichtenboxResult;

	/**
	 * Verify an inbound Logius delivery-receipt webhook signature +
	 * payload.
	 *
	 * @param string $rawBody Raw request body (signature
	 *                        is computed over the
	 *                        verbatim bytes).
	 * @param array<string,string> $headers Request headers (Logius
	 *                                      signature is in
	 *                                      `X-Logius-Signature`).
	 *
	 * @return BerichtenboxResult The verification outcome.
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function verifyDeliveryWebhook(string $rawBody, array $headers): BerichtenboxResult;

	/**
	 * Check whether a BSN has an active Berichtenbox mailbox.
	 *
	 * @param string $bsn 9-digit Burgerservicenummer.
	 *
	 * @return BerichtenboxResult The mailbox-status outcome.
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function checkMailbox(string $bsn): BerichtenboxResult;

	/**
	 * Whether the adapter is dormant — i.e. wired but not contacting
	 * Logius.
	 *
	 * @return bool TRUE when the adapter is a log-only stub.
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function isDormant(): bool;
}//end interface
