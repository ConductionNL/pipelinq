<?php

/**
 * Pipelinq BrpRetentionJob.
 *
 * Daily background job that enforces the configurable retention window on BrpPersoon
 * records. Records whose retentieTot is in the past are deleted, and the linked Contact's
 * verifiedBSN / brpPersoonId are reset (audit records remain immutable).
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Retention enforcement (AVG art. 5 — opslagbeperking).
 *
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-008
 */
class BrpRetentionJob extends TimedJob {
	/**
	 * Default interval (24h).
	 */
	private const DEFAULT_INTERVAL_SECONDS = 86400;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory.
	 * @param IAppConfig $appConfig App config.
	 * @param ContainerInterface $container DI.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private IAppConfig $appConfig,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(
			seconds: $this->appConfig->getValueInt(
				AppIdentity::APP_ID,
				'brp.retention_interval_seconds',
				self::DEFAULT_INTERVAL_SECONDS
			)
		);
	}//end __construct()

	/**
	 * Delete expired BrpPersoon records and reset their contacts.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is required by TimedJob::run().
	 *
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-008
	 */
	protected function run(mixed $argument): void {
		try {
			$config = $this->retentionConfig();
			if ($config === null) {
				return;
			}

			[$register, $personSchema, $contactSchema] = $config;

			$objects = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

			$records = $objects->findAll(config: ['filters' => ['register' => $register, 'schema' => $personSchema]]);

			$deleted = 0;
			foreach (($records ?? []) as $record) {
				$arr = $this->recordToArray(rec: $record);
				$wasDeleted = $this->deleteExpiredPerson(
					objects: $objects,
					record: $arr,
					register: $register,
					personSchema: $personSchema,
					contactSchema: $contactSchema,
					now: $now
				);
				$deleted += (int)$wasDeleted;
			}//end foreach

			$this->logger->info('BRP retention sweep complete', ['deleted' => $deleted]);
		} catch (Throwable $e) {
			$this->logger->error('BRP retention job failed', ['error' => $e->getMessage()]);
		}//end try
	}//end run()

	/**
	 * The register, person schema and contact schema, or null (logged) when not configured.
	 *
	 * @return array{0: string, 1: string, 2: string}|null The retention configuration.
	 */
	private function retentionConfig(): array|null {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$personSchema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'brpPersoon_schema', '');
		$contactSchema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'contact_schema', '');
		if ($register === '' || $personSchema === '') {
			$this->logger->info('BRP retention: schemas not configured; skipping');
			return null;
		}

		return [$register, $personSchema, $contactSchema];
	}//end retentionConfig()

	/**
	 * Coerce an OpenRegister record (array or entity) to a plain array.
	 *
	 * @param mixed $rec Record from ObjectService::findAll()/find().
	 *
	 * @return array<string, mixed> Array representation (empty when unusable).
	 */
	private function recordToArray(mixed $rec): array {
		if (is_array($rec) === true) {
			return $rec;
		}

		if (method_exists($rec, 'jsonSerialize') === true) {
			return (array)$rec->jsonSerialize();
		}

		return [];
	}//end recordToArray()

	/**
	 * Delete a BrpPersoon record when its retention window has elapsed and
	 * reset the linked contact.
	 *
	 * @param object $objects OR ObjectService.
	 * @param array<string, mixed> $record Persoon record data.
	 * @param string $register Register slug.
	 * @param string $personSchema BrpPersoon schema slug.
	 * @param string $contactSchema Contact schema slug.
	 * @param DateTimeImmutable $now Current time (UTC).
	 *
	 * @return bool True when a record was deleted.
	 */
	private function deleteExpiredPerson(
		object $objects,
		array $record,
		string $register,
		string $personSchema,
		string $contactSchema,
		DateTimeImmutable $now,
	): bool {
		if ($this->isRetentionExpired(record: $record, now: $now) === false) {
			return false;
		}

		$uuid = (string)($record['@self']['id'] ?? $record['id'] ?? '');
		$contactId = (string)($record['gekoppeldContact'] ?? '');

		if ($this->deletePerson(objects: $objects, register: $register, personSchema: $personSchema, uuid: $uuid) === false) {
			return false;
		}

		if ($contactId !== '' && $contactSchema !== '') {
			$this->resetContact(objects: $objects, register: $register, contactSchema: $contactSchema, contactId: $contactId, persoonUuid: $uuid);
		}

		return true;
	}//end deleteExpiredPerson()

	/**
	 * Whether the record's retention date has passed (false when absent or unparsable).
	 *
	 * @param array<string, mixed> $record The person record.
	 * @param DateTimeImmutable $now The sweep time (UTC).
	 *
	 * @return bool True when the record may be deleted.
	 */
	private function isRetentionExpired(array $record, DateTimeImmutable $now): bool {
		$retentionTo = (string)($record['retentionTo'] ?? '');
		if ($retentionTo === '') {
			return false;
		}

		try {
			$retentionDt = new DateTimeImmutable($retentionTo, new DateTimeZone('UTC'));
		} catch (Throwable $e) {
			return false;
		}

		return ($retentionDt > $now) === false;
	}//end isRetentionExpired()

	/**
	 * Delete the person object; false (logged) when the delete fails.
	 *
	 * @param object $objects The OpenRegister ObjectService.
	 * @param string $register The register.
	 * @param string $personSchema The BRP person schema.
	 * @param string $uuid The person uuid.
	 *
	 * @return bool True when deleted.
	 */
	private function deletePerson(object $objects, string $register, string $personSchema, string $uuid): bool {
		try {
			$objects->setRegister($register)
				->setSchema($personSchema)
				->deleteObject(uuid: $uuid);
		} catch (Throwable $e) {
			$this->logger->warning(
				'BRP retention: delete failed',
				['uuid' => $uuid, 'error' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end deletePerson()

	/**
	 * Reset Contact.verifiedBSN/brpPersoonId once the linked persoon has been deleted.
	 *
	 * Contact.brpPersoonId is preserved (per spec — it may become a dangling pointer);
	 * verifiedBSN is set to false.
	 *
	 * @param object $objects OR ObjectService.
	 * @param string $register Register ID.
	 * @param string $contactSchema Contact schema ID.
	 * @param string $contactId Contact UUID.
	 * @param string $persoonUuid Recently-deleted BrpPersoon UUID.
	 *
	 * @return void
	 */
	private function resetContact(object $objects, string $register, string $contactSchema, string $contactId, string $persoonUuid): void {
		try {
			$existing = $objects->find(
				id: $contactId,
				register: $register,
				schema: $contactSchema,
			);
			$existingArr = $this->recordToArray(rec: $existing);

			if (empty($existingArr) === true) {
				return;
			}

			if ((string)($existingArr['brpPersonId'] ?? '') !== $persoonUuid) {
				return;
			}

			$existingArr['verifiedBSN'] = false;
			// Per spec REQ-BSN-008-01: brpPersoonId stays (dangling pointer to deleted record).
			$objects->saveObject(
				object: $existingArr,
				extend: [],
				register: $register,
				schema: $contactSchema,
				uuid: $contactId,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'BRP retention: contact reset failed',
				['contactId' => $contactId, 'error' => $e->getMessage()]
			);
		}//end try
	}//end resetContact()
}//end class
