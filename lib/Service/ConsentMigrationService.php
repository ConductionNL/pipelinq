<?php

/**
 * Pipelinq ConsentMigrationService.
 *
 * Moves pipelinq's two consent stores (messagingConsentRecord and
 * consentRecord) into integriq's opt-out table through
 * OptOutChangeRequestedEvent, once. Every record carries its pipelinq UUID as
 * legacyRef, so integriq writes it once however often this runs. The cutover
 * flag `consent.store` becomes `integriq` only after a run with no refusal.
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
 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-s-consent-records-are-migrated-into-integriq-once-req-cii-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * One idempotent migration run, with counts.
 *
 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-s-consent-records-are-migrated-into-integriq-once-req-cii-001
 */
class ConsentMigrationService {

	/**
	 * Constructor.
	 *
	 * @param IntegriqConsentClient    $integriq   Records in integriq and knows the flag.
	 * @param ConsentService           $messaging  Reads messagingConsentRecord.
	 * @param ComplianceService        $compliance Reads consentRecord.
	 * @param IntegriqMarketingConsent $marketing  Maps a consentRecord.
	 * @param ContactAddressLookup     $addresses  The contact's number for a messaging record.
	 * @param IAppConfig               $appConfig  Holds the flag.
	 * @param LoggerInterface          $logger     Logs the counts.
	 */
	public function __construct(
		private readonly IntegriqConsentClient $integriq,
		private readonly ConsentService $messaging,
		private readonly ComplianceService $compliance,
		private readonly IntegriqMarketingConsent $marketing,
		private readonly ContactAddressLookup $addresses,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Migrate every record, then flip the flag when nothing was refused.
	 *
	 * @return array{status:string,migrated:int,skippedBounce:int,skippedNoAddress:int,refused:int,store:string} Counts.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-s-consent-records-are-migrated-into-integriq-once-req-cii-001
	 */
	public function run(): array {
		$counts = ['status' => 'done', 'migrated' => 0, 'skippedBounce' => 0, 'skippedNoAddress' => 0, 'refused' => 0, 'store' => IntegriqConsentClient::STORE_PIPELINQ];
		if ($this->integriq->canRecord() === false) {
			$this->logger->info('Pipelinq consent migration: integriq is not installed, nothing migrated and consent.store stays pipelinq');
			$counts['status'] = 'integriq-missing';
			$counts['store'] = $this->store();
			return $counts;
		}

		foreach ($this->messaging->latestPerPair(rows: $this->messaging->allRecords()) as $row) {
			$address = $this->addresses->addressFor(contactId: (string)($row['contactId'] ?? ''), channel: (string)($row['channel'] ?? ''));
			$counts = $this->hand(counts: $counts, request: $this->messaging->changeRequestFor(row: $row, address: $address));
		}

		foreach ($this->compliance->allConsentRecords() as $row) {
			$request = $this->marketing->changeRequestFor(row: $row, id: $this->compliance->consentRecordId(record: $row));
			if ($request === null) {
				$counts['skippedBounce']++;
				continue;
			}

			$counts = $this->hand(counts: $counts, request: $request);
		}

		if ($counts['refused'] === 0) {
			if ($this->integriq->isCutover() === false) {
				$this->appConfig->setValueString(Application::APP_ID, IntegriqConsentClient::CONFIG_CUTOVER_AT, (string)time());
			}

			$this->appConfig->setValueString(Application::APP_ID, IntegriqConsentClient::CONFIG_STORE, IntegriqConsentClient::STORE_INTEGRIQ);
		}

		if ($counts['refused'] > 0) {
			$counts['status'] = 'refusals';
		}

		$counts['store'] = $this->store();
		$this->logger->info('Pipelinq consent migration into integriq', $counts);
		return $counts;
	}//end run()

	/**
	 * Hand one request to integriq and count the outcome.
	 *
	 * @param array<string,int|string> $counts  The counts so far.
	 * @param array<string,mixed>      $request The change request.
	 *
	 * @return array{status:string,migrated:int,skippedBounce:int,skippedNoAddress:int,refused:int,store:string} The counts.
	 */
	private function hand(array $counts, array $request): array {
		if (trim((string)($request['address'] ?? '')) === '') {
			$counts['skippedNoAddress']++;
			return $counts;
		}

		$outcome = $this->integriq->record(request: $request);
		if ($outcome['recorded'] === true) {
			$counts['migrated']++;
			return $counts;
		}

		$counts['refused']++;
		$this->logger->warning(
			'Pipelinq consent migration: integriq refused a record, consent.store stays pipelinq',
			['legacyRef' => (string)($request['legacyRef'] ?? ''), 'code' => $outcome['code'], 'reason' => $outcome['reason']]
		);
		return $counts;
	}//end hand()

	/**
	 * The current flag.
	 *
	 * @return string The store.
	 */
	private function store(): string {
		if ($this->integriq->isCutover() === true) {
			return IntegriqConsentClient::STORE_INTEGRIQ;
		}

		return IntegriqConsentClient::STORE_PIPELINQ;
	}//end store()
}//end class
