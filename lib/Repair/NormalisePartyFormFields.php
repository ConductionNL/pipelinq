<?php

/**
 * Pipelinq NormalisePartyFormFields.
 *
 * Repair step for two client/contact form changes (pipelinq-forms-review):
 *
 * - D5: a party carried its language twice, `language` (ISO 639-1, from
 *   contact-channel-details) and `correspondenceLanguage` (BCP 47, from
 *   correspondence-language-per-party). The forms keep only
 *   `correspondenceLanguage`. A party that stated `language` and nothing in
 *   `correspondenceLanguage` keeps that statement: it is copied over. An ISO
 *   639-1 code is a valid BCP 47 language tag, so nothing is translated.
 * - D6: `client.industry` became a list of sectors. A stored string is
 *   wrapped into a one-item list, and an empty string becomes an empty list.
 *
 * Idempotent and non-destructive: `language` is never cleared, a set
 * `correspondenceLanguage` is never overwritten, and a list stays a list.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair
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
 * @spec openspec/changes/pipelinq-forms-review/specs/client-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repair step: one language field per party, and industry as a list.
 *
 * @spec openspec/specs/client-management/spec.md
 */
class NormalisePartyFormFields implements IRepairStep {
	/**
	 * Upper bound on rows fetched per schema.
	 *
	 * @var int
	 */
	private const BATCH_LIMIT = 10000;

	/**
	 * The party schemas this step reshapes.
	 *
	 * @var string[]
	 */
	private const OBJECT_TYPES = ['client', 'contact'];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Holds the register and schema ids.
	 * @param ContainerInterface $container Resolves OpenRegister's ObjectService.
	 * @param IGroupManager $groupManager Resolves an acting admin for the save.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ContainerInterface $container,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the repair step name.
	 *
	 * @return string Name.
	 *
	 * @spec openspec/changes/pipelinq-forms-review/specs/client-management/spec.md
	 */
	public function getName(): string {
		return 'Copy a party language into correspondenceLanguage and wrap client industry into a list (idempotent)';
	}//end getName()

	/**
	 * The changes one stored party needs, or [] when it needs none.
	 *
	 * @param array<string,mixed> $row The stored party.
	 *
	 * @return array<string,mixed> The keys to write.
	 *
	 * @spec openspec/changes/pipelinq-forms-review/specs/client-management/spec.md
	 */
	public static function patchFor(array $row): array {
		$patch = [];

		$language = trim((string)($row['language'] ?? ''));
		$correspondence = trim((string)($row['correspondenceLanguage'] ?? ''));
		if ($language !== '' && $correspondence === '') {
			$patch['correspondenceLanguage'] = strtolower($language);
		}

		if (array_key_exists('industry', $row) === true && is_string($row['industry']) === true) {
			$industry = trim($row['industry']);
			$patch['industry'] = [];
			if ($industry !== '') {
				$patch['industry'] = [$industry];
			}
		}

		return $patch;
	}//end patchFor()

	/**
	 * Run the repair.
	 *
	 * @param IOutput $output Output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipelinq-forms-review/specs/client-management/spec.md
	 */
	public function run(IOutput $output): void {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($register === '') {
			$output->info('NormalisePartyFormFields: pipelinq register not configured, skipping');
			return;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			$output->warning('NormalisePartyFormFields: OpenRegister ObjectService unavailable, skipping (' . $e->getMessage() . ')');
			return;
		}

		$actingAdmin = $this->actingAdmin();
		$totals = ['fixed' => 0, 'skipped' => 0, 'stuck' => 0];
		foreach (self::OBJECT_TYPES as $objectType) {
			$schema = $this->appConfig->getValueString(Application::APP_ID, "{$objectType}_schema", '');
			if ($schema === '') {
				continue;
			}

			foreach ($this->readAll(objectService: $objectService, register: $register, schema: $schema) as $row) {
				$outcome = $this->repairRow(
					objectService: $objectService,
					register: $register,
					schema: $schema,
					row: $row,
					actingAdmin: $actingAdmin
				);
				$totals[$outcome]++;
			}
		}

		$output->info(
			sprintf(
				'NormalisePartyFormFields: %d record(s) normalised, %d unchanged, %d failed to save',
				$totals['fixed'],
				$totals['skipped'],
				$totals['stuck']
			)
		);
	}//end run()

	/**
	 * Save one party's patch.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $register The register id.
	 * @param string $schema The schema id.
	 * @param array<string,mixed> $row The stored party.
	 * @param IUser|null $actingAdmin The admin to save as.
	 *
	 * @return string `fixed`, `skipped` or `stuck`.
	 */
	private function repairRow(object $objectService, string $register, string $schema, array $row, ?IUser $actingAdmin): string {
		$uuid = (string)($row['id'] ?? '');
		$patch = self::patchFor(row: $row);
		if ($uuid === '' || $patch === []) {
			return 'skipped';
		}

		try {
			$objectService->saveObject(
				object: array_merge($row, $patch),
				extend: [],
				register: $register,
				schema: $schema,
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false,
				currentUser: $actingAdmin,
			);

			return 'fixed';
		} catch (Throwable $e) {
			$this->logger->warning(
				'NormalisePartyFormFields: failed to save record',
				['uuid' => $uuid, 'schema' => $schema, 'error' => $e->getMessage()]
			);

			return 'stuck';
		}
	}//end repairRow()

	/**
	 * Resolve an admin to act as: a repair step has no session.
	 *
	 * @return IUser|null The first admin, or null when none exists.
	 */
	private function actingAdmin(): ?IUser {
		$admins = ($this->groupManager->get('admin')?->getUsers() ?? []);

		return (array_values($admins)[0] ?? null);
	}//end actingAdmin()

	/**
	 * Read every row of one schema as arrays, RBAC off (no session on the CLI).
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $register The register id.
	 * @param string $schema The schema id.
	 *
	 * @return array<int,array<string,mixed>> The rows.
	 */
	private function readAll(object $objectService, string $register, string $schema): array {
		try {
			$rows = $objectService->findAll(
				config: [
					'filters' => ['register' => $register, 'schema' => $schema],
					'limit' => self::BATCH_LIMIT,
				],
				_rbac: false,
				_multitenancy: false,
			);
		} catch (Throwable $e) {
			$this->logger->warning('NormalisePartyFormFields: findAll failed', ['schema' => $schema, 'error' => $e->getMessage()]);

			return [];
		}

		$out = [];
		foreach ($rows as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$out[] = $row;
			}
		}

		return $out;
	}//end readAll()
}//end class
