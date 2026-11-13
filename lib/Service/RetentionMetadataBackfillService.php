<?php

/**
 * Pipelinq RetentionMetadataBackfillService.
 *
 * Gives records written before their schema declared a retention term the
 * retention metadata OpenRegister writes on create.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/platform-client-retention/tasks.md#3-review-and-back-fill
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Applies OpenRegister's retention metadata to records that have none.
 *
 * OpenRegister writes a record's nomination, term and destruction date when the
 * record is created, from its schema's `archive` configuration. Records made
 * before the schema declared one carry nothing, so they would never be listed.
 * This walks the records of the configured schemas and asks OpenRegister's
 * retention service to apply its metadata to each record without a
 * nomination. Idempotent: a record that has a nomination is left alone, and
 * OpenRegister itself refuses to overwrite one.
 *
 * OpenRegister's retention service and mappers are not part of its published
 * contract, so they are resolved lazily by class name; without them nothing is
 * written.
 *
 * @spec openspec/changes/platform-client-retention/tasks.md#3-review-and-back-fill
 */
class RetentionMetadataBackfillService {

	/**
	 * Rows listed per page.
	 *
	 * @var int
	 */
	private const PAGE_SIZE = 500;

	/**
	 * OpenRegister classes this needs, by role.
	 *
	 * @var array<string, string>
	 */
	private const OPENREGISTER_SERVICES = [
		'retention' => 'OCA\\OpenRegister\\Service\\RetentionService',
		'objects' => 'OCA\\OpenRegister\\Db\\MagicMapper',
		'registers' => 'OCA\\OpenRegister\\Db\\RegisterMapper',
		'schemas' => 'OCA\\OpenRegister\\Db\\SchemaMapper',
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig             $appConfig     Holds the register and schema ids.
	 * @param ObjectServiceInterface $objectService Lists the records.
	 * @param ContainerInterface     $container     Resolves OpenRegister's retention service and mappers.
	 * @param LoggerInterface        $logger        Diagnostics.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Apply retention metadata to every record of the schemas named by the given
	 * app config keys that has none.
	 *
	 * @param array<int, string> $schemaKeys App config keys holding schema ids.
	 *
	 * @return array{status: string, applied: int, skipped: int, failed: int}
	 *         status is done, not-configured or openregister-unavailable.
	 *
	 * @spec openspec/changes/platform-client-retention/tasks.md#3-review-and-back-fill
	 */
	public function backfill(array $schemaKeys): array {
		$counts = ['status' => 'done', 'applied' => 0, 'skipped' => 0, 'failed' => 0];

		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schemas = [];
		foreach ($schemaKeys as $key) {
			$schema = $this->appConfig->getValueString(AppIdentity::APP_ID, $key, '');
			if ($schema !== '') {
				$schemas[] = $schema;
			}
		}

		if ($register === '' || $schemas === []) {
			$counts['status'] = 'not-configured';
			return $counts;
		}

		$services = $this->openRegisterServices();
		if ($services === null) {
			$counts['status'] = 'openregister-unavailable';
			return $counts;
		}

		foreach ($schemas as $schema) {
			$counts = $this->backfillSchema(services: $services, place: ['register' => $register, 'schema' => $schema], counts: $counts);
		}

		return $counts;
	}//end backfill()

	/**
	 * Apply metadata to the records of one schema.
	 *
	 * @param array<string, mixed>                                                   $services OpenRegister's services, by role.
	 * @param array{register: string, schema: string}                                $place    Register and schema ids.
	 * @param array{status: string, applied: int, skipped: int, failed: int}         $counts   Counts so far.
	 *
	 * @return array{status: string, applied: int, skipped: int, failed: int} Counts including this schema.
	 *
	 * @spec openspec/changes/platform-client-retention/tasks.md#3-review-and-back-fill
	 */
	private function backfillSchema(array $services, array $place, array $counts): array {
		try {
			$registerEntity = $services['registers']->find($place['register'], false, false);
			$schemaEntity = $services['schemas']->find($place['schema'], [], false, false);
			$uuids = $this->uuidsOf(register: $place['register'], schema: $place['schema']);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: could not read records for the retention backfill',
				['schema' => $place['schema'], 'exception' => $e->getMessage()]
			);
			$counts['failed']++;
			return $counts;
		}

		foreach ($uuids as $uuid) {
			try {
				$outcome = $this->backfillRecord(services: $services, uuid: $uuid, register: $registerEntity, schema: $schemaEntity);
			} catch (Throwable $e) {
				$this->logger->error(
					'Pipelinq: could not apply retention metadata to a record',
					['uuid' => $uuid, 'exception' => $e->getMessage()]
				);
				$outcome = 'failed';
			}

			$counts = self::counted(counts: $counts, outcome: $outcome);
		}

		return $counts;
	}//end backfillSchema()

	/**
	 * Apply metadata to one record, unless it has a nomination already.
	 *
	 * @param array<string, mixed> $services OpenRegister's services, by role.
	 * @param string               $uuid     The record.
	 * @param mixed                $register OpenRegister's register entity.
	 * @param mixed                $schema   OpenRegister's schema entity.
	 *
	 * @return string applied or skipped.
	 *
	 * @spec openspec/changes/platform-client-retention/tasks.md#3-review-and-back-fill
	 */
	private function backfillRecord(array $services, string $uuid, mixed $register, mixed $schema): string {
		$object = $services['objects']->find($uuid, $register, $schema, false, false, false);
		if (self::isNominated(retention: $object->getRetention()) === true) {
			return 'skipped';
		}

		$object = $services['retention']->applyArchivalMetadata($object, $schema);
		if (self::isNominated(retention: $object->getRetention()) === false) {
			// The schema declares no enabled term, so there is nothing to store.
			return 'skipped';
		}

		$services['objects']->update($object, $register, $schema);

		return 'applied';
	}//end backfillRecord()

	/**
	 * The counts with one more record of the given outcome.
	 *
	 * @param array{status: string, applied: int, skipped: int, failed: int} $counts  Counts so far.
	 * @param string                                                         $outcome applied, skipped or failed.
	 *
	 * @return array{status: string, applied: int, skipped: int, failed: int}
	 */
	private static function counted(array $counts, string $outcome): array {
		$key = match ($outcome) {
			'applied' => 'applied',
			'skipped' => 'skipped',
			default => 'failed',
		};
		$counts[$key] += 1;

		return $counts;
	}//end counted()

	/**
	 * Whether a retention block carries a nomination.
	 *
	 * @param mixed $retention The record's retention block.
	 *
	 * @return bool
	 */
	private static function isNominated(mixed $retention): bool {
		return is_array($retention) === true && empty($retention['archiefnominatie']) === false;
	}//end isNominated()

	/**
	 * The uuids of every record of a schema, read page by page.
	 *
	 * @param string $register Register id.
	 * @param string $schema   Schema id.
	 *
	 * @return array<int, string>
	 */
	private function uuidsOf(string $register, string $schema): array {
		$uuids = [];
		$offset = 0;
		do {
			$rows = $this->objectService->findAll(
				config: [
					'filters' => ['register' => $register, 'schema' => $schema],
					'limit' => self::PAGE_SIZE,
					'offset' => $offset,
				],
				_rbac: false,
				_multitenancy: false,
			);
			foreach ($rows as $row) {
				$uuid = (string)($row->getUuid() ?? '');
				if ($uuid !== '') {
					$uuids[] = $uuid;
				}
			}

			$offset += self::PAGE_SIZE;
			$fullPage = count($rows) === self::PAGE_SIZE;
		} while ($fullPage === true);

		return $uuids;
	}//end uuidsOf()

	/**
	 * OpenRegister's retention service and mappers, or null when one is missing.
	 *
	 * @return array<string, mixed>|null
	 */
	private function openRegisterServices(): ?array {
		$services = [];
		foreach (self::OPENREGISTER_SERVICES as $role => $class) {
			try {
				$services[$role] = $this->container->get($class);
			} catch (Throwable $e) {
				$this->logger->info(
					'Pipelinq: retention backfill skipped, OpenRegister service unavailable',
					['class' => $class, 'exception' => $e->getMessage()]
				);
				return null;
			}
		}

		return $services;
	}//end openRegisterServices()
}//end class
