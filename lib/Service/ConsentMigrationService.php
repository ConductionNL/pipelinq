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
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

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
	 * @param ContainerInterface       $container  Resolves OpenRegister's ObjectService to read consentRecord.
	 * @param IntegriqMarketingConsent $marketing  Maps a consentRecord.
	 * @param ContactAddressLookup     $addresses  The contact's number for a messaging record.
	 * @param IAppConfig               $appConfig  Holds the flag.
	 * @param LoggerInterface          $logger     Logs the counts.
	 */
	public function __construct(
		private readonly IntegriqConsentClient $integriq,
		private readonly ConsentService $messaging,
		private readonly ContainerInterface $container,
		private readonly IntegriqMarketingConsent $marketing,
		private readonly ContactAddressLookup $addresses,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The counts of the current run.
	 *
	 * @var array{migrated:int,skippedBounce:int,skippedNoAddress:int,refused:int}
	 */
	private array $counts = ['migrated' => 0, 'skippedBounce' => 0, 'skippedNoAddress' => 0, 'refused' => 0];

	/**
	 * Migrate every record, then flip the flag when nothing was refused.
	 *
	 * @return array{status:string,migrated:int,skippedBounce:int,skippedNoAddress:int,refused:int,store:string} Counts.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-s-consent-records-are-migrated-into-integriq-once-req-cii-001
	 */
	public function run(): array {
		$this->counts = ['migrated' => 0, 'skippedBounce' => 0, 'skippedNoAddress' => 0, 'refused' => 0];
		if ($this->integriq->canRecord() === false) {
			$this->logger->info('Pipelinq consent migration: integriq is not installed, nothing migrated and consent.store stays pipelinq');
			return $this->result(status: 'integriq-missing');
		}

		foreach ($this->messaging->latestPerPair(rows: $this->messaging->allRecords()) as $row) {
			$address = $this->addresses->addressFor(contactId: (string)($row['contactId'] ?? ''), channel: (string)($row['channel'] ?? ''));
			$this->hand(request: $this->messaging->changeRequestFor(row: $row, address: $address));
		}

		foreach ($this->consentRecords() as $row) {
			$request = $this->marketing->changeRequestFor(row: $row, id: $this->uuidOf(row: $row));
			if ($request === null) {
				$this->counts['skippedBounce']++;
				continue;
			}

			$this->hand(request: $request);
		}

		$status = 'done';
		if ($this->counts['refused'] === 0) {
			if ($this->integriq->isCutover() === false) {
				$this->appConfig->setValueString(Application::APP_ID, IntegriqConsentClient::CONFIG_CUTOVER_AT, (string)time());
			}

			$this->appConfig->setValueString(Application::APP_ID, IntegriqConsentClient::CONFIG_STORE, IntegriqConsentClient::STORE_INTEGRIQ);
		}

		if ($this->counts['refused'] > 0) {
			$status = 'refusals';
		}

		$result = $this->result(status: $status);
		$this->logger->info('Pipelinq consent migration into integriq', $result);
		return $result;
	}//end run()

	/**
	 * Hand one request to integriq and count the outcome.
	 *
	 * @param array<string,mixed> $request The change request.
	 *
	 * @return void
	 */
	private function hand(array $request): void {
		if (trim((string)($request['address'] ?? '')) === '') {
			$this->counts['skippedNoAddress']++;
			return;
		}

		$outcome = $this->integriq->record(request: $request);
		if ($outcome['recorded'] === true) {
			$this->counts['migrated']++;
			return;
		}

		$this->counts['refused']++;
		$this->logger->warning(
			'Pipelinq consent migration: integriq refused a record, consent.store stays pipelinq',
			['legacyRef' => (string)($request['legacyRef'] ?? ''), 'code' => $outcome['code'], 'reason' => $outcome['reason']]
		);
	}//end hand()

	/**
	 * The run's outcome.
	 *
	 * @param string $status `done`, `refusals` or `integriq-missing`.
	 *
	 * @return array{status:string,migrated:int,skippedBounce:int,skippedNoAddress:int,refused:int,store:string} Counts.
	 */
	private function result(string $status): array {
		return ['status' => $status] + $this->counts + ['store' => $this->store()];
	}//end result()

	/**
	 * Every consentRecord in pipelinq's register.
	 *
	 * @return array<int,array<string,mixed>> The records.
	 */
	private function consentRecords(): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'consent_record_schema', '');
		if ($register === '') {
			$register = 'pipelinq';
		}

		if ($schema === '') {
			$schema = 'consentRecord';
		}

		try {
			$rows = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService')
				->findAll(config: ['filters' => ['register' => $register, 'schema' => $schema]]);
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq consent migration: consentRecords unreadable', ['exception' => $e->getMessage()]);
			return [];
		}

		$out = [];
		foreach ((array)$rows as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$out[] = $row;
			}
		}

		return $out;
	}//end consentRecords()

	/**
	 * The UUID of a record.
	 *
	 * @param array<string,mixed> $row The record.
	 *
	 * @return string The UUID, or empty.
	 */
	private function uuidOf(array $row): string {
		foreach ([$row['uuid'] ?? null, $row['id'] ?? null, $row['@self']['uuid'] ?? null, $row['@self']['id'] ?? null] as $value) {
			if (is_scalar($value) === true && (string)$value !== '') {
				return (string)$value;
			}
		}

		return '';
	}//end uuidOf()

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
