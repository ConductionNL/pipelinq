<?php

/**
 * Contract of ConsentService
 *
 * The public surface of ConsentService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

/**
 * Contract of ConsentService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ConsentServiceInterface {

	/**
	 * The latest consent state for one (contactId, channel) pair.
	 *
	 * Returns `opted-in` / `opted-out` when a record exists, otherwise
	 * `unknown`. Used by the send surface to display consent state (REQ-OM-005)
	 * without deciding whether a send is allowed — that stays with
	 * {@see canSend()} / {@see canSendBusinessInitiated()}.
	 *
	 * @param string $contactId Contact UUID.
	 * @param string $channel `whatsapp` or `sms`.
	 *
	 * After the cutover the state is derived from integriq's decision (Ruben,
	 * 2026-10-05): refused as `opted-out` reads `opted-out`, allowed while
	 * consent was required means a consent matched and reads `opted-in`,
	 * anything else reads `unknown`.
	 *
	 * @return string `opted-in` / `opted-out` / `unknown`.
	 *
	 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-005-consent-gating-and-recording
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-lateststate-is-derived-from-integriq-s-decision-req-cii-006
	 * @spec openspec/specs/consent-in-integriq/spec.md#requirement-lateststate-asks-integriq-as-a-probe-req-cii-007
	 */
	public function latestState(string $contactId, string $channel): string;

	/**
	 * Append an `opted-in` consent record.
	 *
	 * @param string $contactId Contact UUID.
	 * @param string $channel `whatsapp` or `sms`.
	 * @param string $source Enum value (webform / chat-reply / ...).
	 * @param string $evidence Free-text audit-trail evidence.
	 * @param string $legalBasis GDPR legal basis (consent / legitimate-interest / ...).
	 * @param string $address The number the wish is for; looked up when empty.
	 *
	 * @return array<string, mixed>|null Saved row or null on failure.
	 *
	 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-writes-every-wish-to-integriq-req-cii-003
	 */
	public function recordOptIn(
		string $contactId,
		string $channel,
		string $source,
		string $evidence,
		string $legalBasis = 'consent',
		string $address = '',
	): ?array;

	/**
	 * Append an `opted-out` consent record.
	 *
	 * @param string $contactId Contact UUID.
	 * @param string $channel `whatsapp` or `sms`.
	 * @param string $source Enum value (keyword-stop / admin-override / ...).
	 * @param string $evidence Free-text audit-trail evidence.
	 * @param string $legalBasis GDPR legal basis (consent / legitimate-interest / ...).
	 * @param string $address The number the wish is for; looked up when empty.
	 *
	 * @return array<string, mixed>|null Saved row or null on failure.
	 *
	 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-writes-every-wish-to-integriq-req-cii-003
	 */
	public function recordOptOut(
		string $contactId,
		string $channel,
		string $source,
		string $evidence,
		string $legalBasis = 'consent',
		string $address = '',
	): ?array;

	/**
	 * Replay to integriq the fallback records written after the cutover.
	 *
	 * Oldest first, each with its legacy ref, so a record integriq already
	 * has writes nothing and a STOP written while integriq was away lands
	 * before a newer wish.
	 *
	 * @param string $contactId Only this contact's records; every contact when empty.
	 *
	 * @return array{replayed: int, failed: int} Counts.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-writes-every-wish-to-integriq-req-cii-003
	 */
	public function replayPending(string $contactId = ''): array;
}//end interface
