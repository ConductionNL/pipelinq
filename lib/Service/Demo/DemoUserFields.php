<?php

/**
 * Pipelinq DemoUserFields.
 *
 * Points the user fields of the example records at people who exist on this
 * server. The example descriptor names demo users (`jan.smit`, `emma.bakker`,
 * `uid:agent.bakker`, ...) that no instance has, and OpenRegister refuses a
 * `format: user` value that is not a real account, so 40 example records were
 * skipped on every load (pipelinq-audit-admin-forms-pos). A value that is a
 * real user stays; any other becomes the person who loads the examples, or is
 * left out when nobody is signed in (the occ path).
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
 * @spec openspec/specs/example-data/spec.md#requirement-every-example-record-imports
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Demo;

use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Rewrites user fields in example records to existing users.
 *
 * @spec openspec/specs/example-data/spec.md#requirement-every-example-record-imports
 */
class DemoUserFields {
	/**
	 * Constructor.
	 *
	 * @param IUserSession            $userSession  Who loads the examples.
	 * @param IUserManager            $userManager  Tells a real account from a demo name.
	 * @param ConfigFileLoaderService $configLoader The merged schemas, to find the user fields.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IUserManager $userManager,
		private readonly ConfigFileLoaderService $configLoader,
	) {
	}//end __construct()

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
	 * @spec openspec/specs/example-data/spec.md#requirement-every-example-record-imports
	 */
	public function withRealUsers(array $objects): array {
		$schemas = (array)($this->configLoader->loadConfigurationFile()['components']['schemas'] ?? []);

		return $this->assign(
			objects: $objects,
			schemas: $schemas,
			userExists: fn (string $uid): bool => $this->userManager->userExists($uid),
			actingUid: $this->userSession->getUser()?->getUID()
		);
	}//end withRealUsers()
	/**
	 * Point every user field at an existing user, or leave it out.
	 *
	 * @param array<int, array<string, mixed>>    $objects    The example records.
	 * @param array<string, array<string, mixed>> $schemas    The merged schemas, keyed by slug.
	 * @param callable(string): bool              $userExists Whether a uid is a real account.
	 * @param string|null                         $actingUid  Who loads the examples, or null.
	 *
	 * @return array<int, array<string, mixed>> The records with real users only.
	 *
	 * @spec openspec/specs/example-data/spec.md#requirement-every-example-record-imports
	 */
	public function assign(array $objects, array $schemas, callable $userExists, ?string $actingUid): array {
		$accounts = new ExampleUserAccounts(userExists: $userExists(...), actingUid: $actingUid);
		foreach ($objects as $index => $object) {
			$schemaSlug = (string)($object['@self']['schema'] ?? '');
			$objects[$index] = $accounts->assignFields(object: $object, properties: (array)($schemas[$schemaSlug]['properties'] ?? []));
		}

		return $objects;
	}//end assign()
}//end class
