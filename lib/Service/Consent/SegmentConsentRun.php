<?php

/**
 * Pipelinq SegmentConsentRun.
 *
 * One consent check over the members of a Segment.
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
 * @spec openspec/specs/marketing-compliance/spec.md#requirement-blast-cannot-send-without-lawful-basis
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Consent;

/**
 * The channel and intent a Segment is checked for, integriq's decision per
 * member when it answers, and what the check found: members missing
 * consent, members suppressed, and integriq's link per allowed member.
 * Lives for one checkSegmentCompliance() call.
 *
 * @spec openspec/specs/marketing-compliance/spec.md#requirement-blast-cannot-send-without-lawful-basis
 */
final class SegmentConsentRun {

	/**
	 * Contact ids without a usable consent.
	 *
	 * @var array<int, string>
	 */
	private array $missing = [];

	/**
	 * Contact ids suppressed for dunning.
	 *
	 * @var array<int, string>
	 */
	private array $suppressed = [];

	/**
	 * Integriq's unsubscribe link per allowed contact id.
	 *
	 * @var array<string, string>
	 */
	private array $unsubscribe = [];

	/**
	 * Integriq's decision per contact id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $decisions = [];

	/**
	 * Constructor.
	 *
	 * @param string $channel The lower-cased channel.
	 * @param string $intent  `promotional` or `service`.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $channel,
		public readonly string $intent,
	) {
	}//end __construct()

	/**
	 * Use integriq's decisions for the members.
	 *
	 * @param array<string, array<string, mixed>> $decisions Decision per contact id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-asks-integriq-before-every-non-exempt-message-req-cii-002
	 */
	public function useDecisions(array $decisions): void {
		$this->decisions = $decisions;
	}//end useDecisions()

	/**
	 * Integriq's decision for one member; no decision is a refusal.
	 *
	 * @param string $contactId The member's contact id.
	 *
	 * @return array<string, mixed> The decision.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-asks-integriq-before-every-non-exempt-message-req-cii-002
	 */
	public function decisionFor(string $contactId): array {
		return ($this->decisions[$contactId] ?? ['send' => false, 'unsubscribe' => null]);
	}//end decisionFor()

	/**
	 * Record a member without a usable consent.
	 *
	 * @param string $contactId The member's contact id, empty when it has none.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/marketing-compliance/spec.md#requirement-blast-cannot-send-without-lawful-basis
	 */
	public function missing(string $contactId): void {
		$this->missing[] = $contactId;
	}//end missing()

	/**
	 * Record a member suppressed for dunning.
	 *
	 * @param string $contactId The member's contact id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/marketing-compliance/spec.md#requirement-blast-cannot-send-without-lawful-basis
	 */
	public function suppressed(string $contactId): void {
		$this->suppressed[] = $contactId;
	}//end suppressed()

	/**
	 * Record integriq's unsubscribe link for an allowed member.
	 *
	 * @param string $contactId The member's contact id.
	 * @param string $url       The link, empty when integriq gave none.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-asks-integriq-before-every-non-exempt-message-req-cii-002
	 */
	public function link(string $contactId, string $url): void {
		$this->unsubscribe[$contactId] = $url;
	}//end link()

	/**
	 * The check result, each contact listed once.
	 *
	 * `compliant` still means "every member has a lawful basis". A
	 * suppressed contact is one the tenant MAY mail and chose not to, which
	 * is a different thing from one it may not, and collapsing the two would
	 * report a lawful campaign as non-compliant.
	 *
	 * @return array{compliant: bool, missingConsent: array<int, string>, missingCount: int, suppressed: array<int, string>, suppressedCount: int}
	 *
	 * @spec openspec/specs/marketing-compliance/spec.md#requirement-blast-cannot-send-without-lawful-basis
	 */
	public function result(): array {
		$missing = array_values(array_unique($this->missing));
		$suppressed = array_values(array_unique($this->suppressed));
		return [
			'compliant' => ($missing === []),
			'missingConsent' => $missing,
			'missingCount' => count($missing),
			'suppressed' => $suppressed,
			'suppressedCount' => count($suppressed),
		];
	}//end result()

	/**
	 * The check result plus integriq's non-empty link per allowed member.
	 *
	 * @return array{
	 *     compliant: bool,
	 *     missingConsent: array<int, string>,
	 *     missingCount: int,
	 *     suppressed: array<int, string>,
	 *     suppressedCount: int,
	 *     unsubscribe: array<string, string>
	 * }
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-asks-integriq-before-every-non-exempt-message-req-cii-002
	 */
	public function resultWithLinks(): array {
		return $this->result() + ['unsubscribe' => array_filter($this->unsubscribe)];
	}//end resultWithLinks()
}//end class
