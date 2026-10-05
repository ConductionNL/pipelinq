<?php

/**
 * Pipelinq IntegriqMarketingConsent.
 *
 * The marketing side of the cutover: blasts, journeys and list mail ask
 * integriq, and list consent, the preference centre and withdrawals write to
 * integriq. ComplianceService keeps its signatures and its dunning
 * suppression and bounce handling, and hands this class the consent question.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-asks-integriq-before-every-non-exempt-message-req-cii-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * Marketing consent questions and writes, through integriq.
 *
 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-asks-integriq-before-every-non-exempt-message-req-cii-002
 */
class IntegriqMarketingConsent {

	/**
	 * Withdrawal reasons that are deliverability, not a wish. They stay in pipelinq.
	 *
	 * @var array<int,string>
	 */
	public const BOUNCE_REASONS = ['bounce-hard', 'bounce-soft-x5'];

	/**
	 * The legacy-ref suffix per state.
	 *
	 * @var array<string,string>
	 */
	private const STATE_SUFFIX = ['opted-in' => 'in', 'opted-out' => 'out'];

	/**
	 * Constructor.
	 *
	 * @param IntegriqConsentClient $integriq  Asks and records.
	 * @param ContactAddressLookup  $addresses The contact's email or phone.
	 */
	public function __construct(
		private readonly IntegriqConsentClient $integriq,
		private readonly ContactAddressLookup $addresses,
	) {
	}//end __construct()

	/**
	 * Whether the cutover happened.
	 *
	 * @return bool True when integriq answers pipelinq's consent questions.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-s-consent-records-are-migrated-into-integriq-once-req-cii-001
	 */
	public function isActive(): bool {
		return $this->integriq->isCutover();
	}//end isActive()

	/**
	 * The category and consent flag for a send intent.
	 *
	 * @param string $intent `promotional` or `service`.
	 *
	 * @return array{0:string,1:bool} Category and requiresConsent.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-asks-integriq-before-every-non-exempt-message-req-cii-002
	 */
	public static function categoryFor(string $intent): array {
		if ($intent === ComplianceService::INTENT_SERVICE) {
			return [IntegriqConsentClient::CATEGORY_SERVICE, false];
		}

		return [IntegriqConsentClient::CATEGORY_MARKETING, true];
	}//end categoryFor()

	/**
	 * Ask integriq about one contact.
	 *
	 * @param string      $contactId       Contact UUID, or the address of a public signup.
	 * @param string      $channel         `email` or `sms`.
	 * @param string      $category        The category.
	 * @param bool        $requiresConsent Whether a consent must match.
	 * @param string|null $listId          The list, for a list send.
	 *
	 * @return array{send:bool,code:string,reason:string,unsubscribe:array<string,mixed>|null} The decision.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) requiresConsent is integriq's contract field.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-asks-integriq-before-every-non-exempt-message-req-cii-002
	 */
	public function decide(string $contactId, string $channel, string $category, bool $requiresConsent, ?string $listId = null): array {
		return $this->integriq->decideOne(
			channel: $channel,
			category: $category,
			requiresConsent: $requiresConsent,
			address: $this->addresses->addressFor(contactId: $contactId, channel: $channel),
			contactRef: $this->contactRefOf(contactId: $contactId),
			listRef: (string)$listId,
		);
	}//end decide()

	/**
	 * Ask integriq about a whole segment, one event per 500 members.
	 *
	 * @param array<int,array<string,mixed>> $members  Segment members, each with `contactId` and maybe `email`.
	 * @param string                         $channel  `email` or `sms`.
	 * @param string                         $category The category.
	 * @param bool                           $requiresConsent Whether a consent must match.
	 *
	 * @return array<string,array{send:bool,code:string,reason:string,unsubscribe:array<string,mixed>|null}> By contact id.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) requiresConsent is integriq's contract field.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-asks-integriq-before-every-non-exempt-message-req-cii-002
	 */
	public function decideMembers(array $members, string $channel, string $category, bool $requiresConsent): array {
		$recipients = [];
		$byAddress = [];
		foreach ($members as $member) {
			$contactId = (string)($member['contactId'] ?? '');
			if ($contactId === '') {
				continue;
			}

			$address = '';
			if ($channel === 'email') {
				$address = trim((string)($member['email'] ?? ''));
			}

			if ($address === '') {
				$address = $this->addresses->addressFor(contactId: $contactId, channel: $channel);
			}

			if ($address === '') {
				continue;
			}

			$recipients[] = ['address' => $address, 'contactRef' => $this->contactRefOf(contactId: $contactId)];
			$byAddress[$address][] = $contactId;
		}//end foreach

		$out = [];
		$decisions = $this->integriq->decide(channel: $channel, category: $category, requiresConsent: $requiresConsent, recipients: $recipients);
		foreach ($decisions as $address => $decision) {
			foreach (($byAddress[$address] ?? []) as $contactId) {
				$out[$contactId] = $decision;
			}
		}

		return $out;
	}//end decideMembers()

	/**
	 * Hand integriq a list consent.
	 *
	 * @param string              $contactId   Contact UUID, or a signup address.
	 * @param string              $listId      The list.
	 * @param string              $channel     The channel.
	 * @param string              $lawfulBasis The AVG article 6 basis.
	 * @param string              $source      How the consent was given.
	 * @param array<string,mixed> $evidence    What was shown and when.
	 *
	 * @return bool True when integriq took it; false keeps it in pipelinq.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-writes-every-wish-to-integriq-req-cii-003
	 */
	public function recordListConsent(
		string $contactId,
		string $listId,
		string $channel,
		string $lawfulBasis,
		string $source,
		array $evidence,
	): bool {
		$outcome = $this->integriq->record(
			request: [
				'address' => $this->addresses->addressFor(contactId: $contactId, channel: $channel),
				'state' => 'opted-in',
				'scope' => 'list',
				'channel' => $channel,
				'ref' => $listId,
				'contactRef' => $this->contactRefOf(contactId: $contactId),
				'lawfulBasis' => $lawfulBasis,
				'evidence' => $evidence,
				'source' => $source,
				'purpose' => IntegriqConsentClient::CATEGORY_MARKETING,
			]
		);

		return $outcome['recorded'];
	}//end recordListConsent()

	/**
	 * Hand integriq a withdrawal. A bounce is not a wish and is never handed over.
	 *
	 * @param string      $contactId Contact UUID, or a signup address.
	 * @param string      $channel   The channel.
	 * @param string      $reason    The withdrawal reason.
	 * @param string|null $listId    The list, or null for the channel.
	 *
	 * @return bool True when integriq took it; false keeps it in pipelinq.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-writes-every-wish-to-integriq-req-cii-003
	 */
	public function recordWithdrawal(string $contactId, string $channel, string $reason, ?string $listId = null): bool {
		if (in_array($reason, self::BOUNCE_REASONS, true) === true) {
			return false;
		}

		$scope = 'channel';
		if ($listId !== null && $listId !== '') {
			$scope = 'list';
		}

		$outcome = $this->integriq->record(
			request: [
				'address' => $this->addresses->addressFor(contactId: $contactId, channel: $channel),
				'state' => 'opted-out',
				'scope' => $scope,
				'channel' => $channel,
				'ref' => (string)$listId,
				'contactRef' => $this->contactRefOf(contactId: $contactId),
				'source' => $reason,
				'purpose' => IntegriqConsentClient::CATEGORY_MARKETING,
			]
		);

		return $outcome['recorded'];
	}//end recordWithdrawal()

	/**
	 * The change request for one consentRecord, or null when it is not migrated.
	 *
	 * Mapping of hydra design section 7: a live record is an opt-in on its
	 * channel or list, a withdrawal is an opt-out on the same scope, a bounce
	 * is not migrated.
	 *
	 * @param array<string,mixed> $row The consentRecord.
	 * @param string              $id  Its UUID.
	 *
	 * @return array<string,mixed>|null The request, or null for a bounce.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-s-consent-records-are-migrated-into-integriq-once-req-cii-001
	 */
	public function changeRequestFor(array $row, string $id): ?array {
		$withdrawnAt = trim((string)($row['withdrawnAt'] ?? ''));
		$reason = (string)($row['withdrawnReason'] ?? '');
		if ($withdrawnAt !== '' && in_array($reason, self::BOUNCE_REASONS, true) === true) {
			return null;
		}

		$contactId = (string)($row['contactId'] ?? '');
		$channel = strtolower((string)($row['channel'] ?? ''));
		$listId = trim((string)($row['listId'] ?? ''));
		$state = 'opted-in';
		if ($withdrawnAt !== '') {
			$state = 'opted-out';
		}

		$scope = 'channel';
		if ($listId !== '') {
			$scope = 'list';
		}

		$evidence = ($row['evidence'] ?? []);
		if (is_array($evidence) === false) {
			$evidence = ['text' => (string)$evidence];
		}

		$request = [
			'address' => $this->addresses->addressFor(contactId: $contactId, channel: $channel),
			'state' => $state,
			'scope' => $scope,
			'channel' => $channel,
			'ref' => $listId,
			'contactRef' => $this->contactRefOf(contactId: $contactId),
			'evidence' => $evidence,
			'source' => (string)($row['consentSource'] ?? ''),
			'purpose' => IntegriqConsentClient::CATEGORY_MARKETING,
			// The UUID plus the state, so a later withdrawal of the same record
			// is a new wish. Fits integriq's 64-character legacy_uuid column.
			'legacyRef' => $id.':'.self::STATE_SUFFIX[$state],
		];
		if ($state === 'opted-in') {
			$request['lawfulBasis'] = (string)($row['lawfulBasis'] ?? '');
		}

		if ($state === 'opted-out') {
			$request['source'] = $reason;
		}

		return $request;
	}//end changeRequestFor()

	/**
	 * Hand integriq one prepared change request.
	 *
	 * @param array<string,mixed> $request The request.
	 *
	 * @return array{recorded:bool,recordId:int|null,code:string,reason:string} The outcome.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-s-consent-records-are-migrated-into-integriq-once-req-cii-001
	 */
	public function record(array $request): array {
		return $this->integriq->record(request: $request);
	}//end record()

	/**
	 * The contact ref integriq keeps: a UUID, never an address.
	 *
	 * @param string $contactId The pipelinq contact id.
	 *
	 * @return string The ref, or empty for a signup recorded under an address.
	 */
	private function contactRefOf(string $contactId): string {
		if (str_contains($contactId, '@') === true) {
			return '';
		}

		return $contactId;
	}//end contactRefOf()
}//end class
