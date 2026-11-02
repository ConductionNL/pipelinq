<?php

/**
 * Pipelinq ConsentWish.
 *
 * One opt-in or opt-out, as pipelinq records it.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Consent
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-writes-every-wish-to-integriq-req-cii-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Consent;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The state, trigger, evidence and legal basis of one consent wish.
 *
 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-writes-every-wish-to-integriq-req-cii-003
 */
class ConsentWish {
	/**
	 * Constructor.
	 *
	 * @param string $state      `opted-in` or `opted-out`.
	 * @param string $source     The trigger (webform, keyword-stop, ...).
	 * @param string $evidence   Free-text audit-trail evidence.
	 * @param string $legalBasis GDPR legal basis, sent for an opt-in only.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $state,
		public readonly string $source,
		public readonly string $evidence,
		public readonly string $legalBasis,
	) {
	}//end __construct()

	/**
	 * The change request integriq stores for this wish.
	 *
	 * @param ConsentSubject $subject The subject, with the address already resolved.
	 *
	 * @return array<string, mixed> The request.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-writes-every-wish-to-integriq-req-cii-003
	 */
	public function changeRequest(ConsentSubject $subject): array {
		$request = [
			'address' => $subject->address,
			'state' => $this->state,
			'scope' => 'channel',
			'channel' => $subject->channel,
			'contactRef' => $subject->contactId,
			'source' => $this->source,
			'evidence' => ['text' => $this->evidence],
		];
		if ($this->state === 'opted-in') {
			$request['lawfulBasis'] = $this->legalBasis;
		}

		return $request;
	}//end changeRequest()

	/**
	 * The messagingConsentRecord row pipelinq appends for this wish.
	 *
	 * @param ConsentSubject $subject Who and which channel it is for.
	 *
	 * @return array<string, mixed> The row; an empty legal basis reads `consent`.
	 *
	 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
	 */
	public function storedRow(ConsentSubject $subject): array {
		$basis = $this->legalBasis;
		if ($basis === '') {
			$basis = 'consent';
		}

		return [
			'contactId' => $subject->contactId,
			'channel' => $subject->channel,
			'state' => $this->state,
			'source' => $this->source,
			'evidence' => $this->evidence,
			'recordedAt' => $this->nowIso(),
			'legalBasis' => $basis,
		];
	}//end storedRow()

	/**
	 * Current ISO 8601 UTC timestamp, to MICROSECOND resolution.
	 *
	 * 🔴 SECONDS ARE NOT ENOUGH TO ORDER CONSENT.
	 *
	 * This was gmdate('Y-m-d\TH:i:s\Z'), and ConsentService::loadLatestRecord() orders consent
	 * events by this value. Two changes in the SAME SECOND therefore had no
	 * defined order, and "the latest consent" — which is the whole question
	 * ConsentService answers, and a legally meaningful one — came down to which
	 * row the store happened to return first. Opting out and back in inside one
	 * second could leave the opt-out winning.
	 *
	 * ConsentServiceTest carried a sleep() to work around it, with a comment
	 * saying so, and still failed roughly one run in five.
	 *
	 * Microseconds keep the value ISO 8601 and keep it string-sortable; the
	 * comparison in ConsentService::loadLatestRecord() parses rather than strcmp's, so records
	 * written at the old resolution still order correctly against new ones.
	 *
	 * @return string Timestamp.
	 */
	private function nowIso(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
	}//end nowIso()
}//end class
