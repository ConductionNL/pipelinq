<?php

/**
 * Pipelinq DemoRegisterImporter.
 *
 * Imports `lib/Settings/pipelinq_example_register.json` on request and removes
 * it again. These are the example records (point of sale, bookings, marketing,
 * ZGW bridge, forecasts and the original sample clients, leads and tickets)
 * that used to sit in the register descriptor itself, where OpenRegister
 * imported them on every install, upgrade and provisioning run whatever the
 * operator picked in the setup wizard.
 *
 * 🔴 ON DEMAND ONLY, NEVER ON INSTALL. The descriptor is `x-openregister.type:
 * mock`, which OpenRegister never imports by itself, and it is imported under
 * its own configuration identity (`pipelinq.demo`) so its version gate cannot
 * mask, or be masked by, the real register import.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Demo
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/example-data-out-of-the-register/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Demo;

use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Imports and removes the example records descriptor.
 *
 * @spec openspec/changes/example-data-out-of-the-register/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
 */
class DemoRegisterImporter {
	/**
	 * App-relative path to the example records descriptor.
	 *
	 * @var string
	 */
	private const DESCRIPTOR = '/lib/Settings/pipelinq_example_register.json';

	/**
	 * Configuration identity for the example import, never the app id.
	 *
	 * @var string
	 */
	public const CONFIG_APP_ID = Application::APP_ID . '.demo';

	/**
	 * Fields that name a record, checked in order, to confirm a slug match.
	 *
	 * @var array<int, string>
	 */
	private const LABEL_FIELDS = ['name', 'title', 'label', 'displayName', 'reference', 'subject', 'code'];

	/**
	 * Constructor.
	 *
	 * OpenRegister's services are injected, not looked up (ADR-083): pipelinq
	 * requires OpenRegister, and only the setup and occ paths construct this class.
	 *
	 * @param IAppManager          $appManager           Resolves this app's path and version.
	 * @param IAppConfig           $appConfig            Holds the provisioned register id.
	 * @param ConfigurationService $configurationService Imports the descriptor and removes recorded imports.
	 * @param SchemaMapper         $schemaMapper         Resolves a schema slug to its id.
	 * @param ObjectService        $objectService        Finds and deletes stored records.
	 * @param LoggerInterface      $logger               Records what was imported or removed.
	 * @param IUserSession         $userSession          Who loads the examples, for their user fields.
	 * @param IUserManager         $userManager          Tells a real account from a demo name.
	 * @param ConfigFileLoaderService $configLoader      The merged schemas, to find the user fields.
	 * @param DemoUserFields       $userFields           Points user fields at existing users.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly IAppConfig $appConfig,
		private readonly ConfigurationService $configurationService,
		private readonly SchemaMapper $schemaMapper,
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
		private readonly IUserSession $userSession,
		private readonly IUserManager $userManager,
		private readonly ConfigFileLoaderService $configLoader,
		private readonly DemoUserFields $userFields,
	) {
	}//end __construct()

	/**
	 * Import the example records descriptor.
	 *
	 * @return array{imported: int, skipped: int} How many records arrived and how many did not.
	 *
	 * @spec openspec/changes/example-data-out-of-the-register/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 */
	public function import(): array {
		$data = $this->loadDescriptor();
		if ($data === null) {
			return ['imported' => 0, 'skipped' => 0];
		}

		$data['components']['objects'] = $this->withRealUsers(objects: $data['components']['objects']);

		$result = $this->configurationService->importFromApp(
			appId: self::CONFIG_APP_ID,
			data: $data,
			version: $this->appManager->getAppVersion(Application::APP_ID),
			force: true
		);

		$skipped = (array)($result['skipped'] ?? []);
		$summary = [
			'imported' => count((array)($result['objects'] ?? [])),
			'skipped'  => (int)($skipped['objects'] ?? 0) + (int)($skipped['seedObjects'] ?? 0),
		];

		$this->logger->info('Pipelinq example records imported', $summary);

		return $summary;
	}//end import()

	/**
	 * Remove every record the descriptor describes, wherever it was imported from.
	 *
	 * Two sources are covered. Imports made through {@see self::import()} are
	 * recorded by OpenRegister and soft-deleted by job. Installs that received
	 * the records from the old register descriptor have no job, so those are
	 * found by schema and slug, and only removed when their name still matches
	 * the descriptor: a record somebody has since renamed is theirs now.
	 *
	 * @return array{removed: int, retained: int}
	 *
	 * @spec openspec/changes/example-data-out-of-the-register/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 */
	public function remove(): array {
		$summary = ['removed' => 0, 'retained' => 0];

		if (method_exists($this->configurationService, 'softDeleteAppImports') === true) {
			$jobs = $this->configurationService->softDeleteAppImports(self::CONFIG_APP_ID);
			$summary['removed'] += (int)($jobs['softDeleted'] ?? 0);
		}

		$data = $this->loadDescriptor();
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($data === null || $registerId === '') {
			return $summary;
		}

		$bySchema = [];
		foreach ($data['components']['objects'] as $object) {
			$bySchema[(string)$object['@self']['schema']][(string)$object['@self']['slug']] = $object;
		}

		foreach ($bySchema as $schemaSlug => $wanted) {
			$counts = $this->removeFromSchema(registerId: $registerId, schemaSlug: $schemaSlug, wanted: $wanted);
			$summary['removed'] += $counts['removed'];
			$summary['retained'] += $counts['retained'];
		}

		$this->logger->info('Pipelinq example records removed', $summary);

		return $summary;
	}//end remove()

	/**
	 * Delete the records of one schema whose slug and name match the descriptor.
	 *
	 * @param string                              $registerId The pipelinq register id.
	 * @param string                              $schemaSlug The schema the records belong to.
	 * @param array<string, array<string, mixed>> $wanted     Descriptor records keyed by slug.
	 *
	 * @return array{removed: int, retained: int}
	 */
	private function removeFromSchema(string $registerId, string $schemaSlug, array $wanted): array {
		$counts = ['removed' => 0, 'retained' => 0];

		try {
			$schema = $this->schemaMapper->find($schemaSlug, _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			// A schema this instance never had holds none of these records.
			return $counts;
		}

		$rows = $this->objectService->findAll(
			[
				'filters' => ['register' => $registerId, 'schema' => (string)$schema->getId()],
				'limit'   => 5000,
			]
		);

		foreach ((array)$rows as $row) {
			$data = $row;
			if ($row instanceof \JsonSerializable === true) {
				$data = $row->jsonSerialize();
			}

			$slug = (string)($data['@self']['slug'] ?? '');
			if (isset($wanted[$slug]) === false || $this->sameLabel(found: $data, wanted: $wanted[$slug]) === false) {
				continue;
			}

			try {
				$this->objectService->deleteObject((string)$data['id'], $registerId, (string)$schema->getId());
				$counts['removed']++;
			} catch (\Throwable $e) {
				if (str_contains($e->getMessage(), 'SCHEMA_ARCHIVAL_IMMUTABLE') === false) {
					throw $e;
				}

				// Append-only schema: the row expires through the retention cron.
				$counts['retained']++;
			}
		}//end foreach

		return $counts;
	}//end removeFromSchema()

	/**
	 * Whether a found record still carries the descriptor's name.
	 *
	 * A record without any naming field matches on slug alone.
	 *
	 * @param array<string, mixed> $found  The stored record.
	 * @param array<string, mixed> $wanted The descriptor record.
	 *
	 * @return bool True when the first naming field the descriptor sets is unchanged.
	 */
	private function sameLabel(array $found, array $wanted): bool {
		foreach (self::LABEL_FIELDS as $field) {
			if (isset($wanted[$field]) === true && is_scalar($wanted[$field]) === true) {
				return (string)($found[$field] ?? '') === (string)$wanted[$field];
			}
		}

		return true;
	}//end sameLabel()

	/**
	 * Point the example records' user fields at accounts that exist here.
	 *
	 * OpenRegister refuses a `format: user` value that is not a real account
	 * and skips the whole record, so a demo name (`jan.smit`) cost the record.
	 *
	 * @param array<int, array<string, mixed>> $objects The example records.
	 *
	 * @return array<int, array<string, mixed>> The records, importable.
	 *
	 * @spec openspec/changes/pipelinq-audit-admin-forms-pos/specs/example-data/spec.md#requirement-every-example-record-imports
	 */
	private function withRealUsers(array $objects): array {
		$schemas = (array)($this->configLoader->loadConfigurationFile()['components']['schemas'] ?? []);
		$acting = $this->userSession->getUser()?->getUID();

		return $this->userFields->assign(
			objects: $objects,
			schemas: $schemas,
			userExists: fn (string $uid): bool => $this->userManager->userExists($uid),
			actingUid: $acting
		);
	}//end withRealUsers()

	/**
	 * Read the descriptor, or null when it is missing or unreadable.
	 *
	 * @return array<string, mixed>|null
	 */
	private function loadDescriptor(): ?array {
		$path = $this->appManager->getAppPath(Application::APP_ID) . self::DESCRIPTOR;
		if (is_readable($path) === false) {
			$this->logger->error('Pipelinq example records descriptor missing', ['path' => $path]);
			return null;
		}

		$data = json_decode((string)file_get_contents($path), true);
		if (is_array($data) === false || is_array($data['components']['objects'] ?? null) === false) {
			$this->logger->error('Pipelinq example records descriptor invalid', ['path' => $path]);
			return null;
		}

		return $data;
	}//end loadDescriptor()
}//end class
