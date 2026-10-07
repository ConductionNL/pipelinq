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
 * @spec openspec/changes/pipelinq-audit-admin-forms-pos/specs/example-data/spec.md#requirement-every-example-record-imports
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Demo;

use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Rewrites user fields in example records to existing users.
 *
 * @spec openspec/changes/pipelinq-audit-admin-forms-pos/specs/example-data/spec.md#requirement-every-example-record-imports
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
	 * @spec openspec/changes/pipelinq-audit-admin-forms-pos/specs/example-data/spec.md#requirement-every-example-record-imports
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
	 * @spec openspec/changes/pipelinq-audit-admin-forms-pos/specs/example-data/spec.md#requirement-every-example-record-imports
	 */
	public function assign(array $objects, array $schemas, callable $userExists, ?string $actingUid): array {
		foreach ($objects as $index => $object) {
			$schemaSlug = (string)($object['@self']['schema'] ?? '');
			$properties = (array)($schemas[$schemaSlug]['properties'] ?? []);

			foreach ($properties as $key => $property) {
				if (array_key_exists($key, $object) === false || is_array($property) === false) {
					continue;
				}

				if ($this->isUserProperty(property: $property) === true) {
					$object = $this->assignOne(object: $object, key: $key, userExists: $userExists, actingUid: $actingUid);
					continue;
				}

				if (($property['type'] ?? '') === 'array'
					&& $this->isUserProperty(property: (array)($property['items'] ?? [])) === true
					&& is_array($object[$key]) === true
				) {
					$object[$key] = $this->assignList(values: $object[$key], userExists: $userExists, actingUid: $actingUid);
				}
			}

			$objects[$index] = $object;
		}//end foreach

		return $objects;
	}//end assign()

	/**
	 * Whether a schema property holds a Nextcloud user id.
	 *
	 * @param array<string, mixed> $property The schema property.
	 *
	 * @return bool True for `format: user` / `username` or a nextcloud-user reference.
	 */
	private function isUserProperty(array $property): bool {
		return in_array(($property['format'] ?? ''), ['user', 'username'], true) === true
			|| ($property['referenceType'] ?? '') === 'nextcloud-user';
	}//end isUserProperty()

	/**
	 * Fix one single-user field.
	 *
	 * @param array<string, mixed>   $object     The record.
	 * @param string                 $key        The field.
	 * @param callable(string): bool $userExists Whether a uid is a real account.
	 * @param string|null            $actingUid  Who loads the examples.
	 *
	 * @return array<string, mixed> The record.
	 */
	private function assignOne(array $object, string $key, callable $userExists, ?string $actingUid): array {
		$value = $object[$key];
		if ($value === null || $value === '' || (is_string($value) === true && $userExists($value) === true)) {
			return $object;
		}

		if ($actingUid === null || $actingUid === '') {
			unset($object[$key]);
			return $object;
		}

		$object[$key] = $actingUid;
		return $object;
	}//end assignOne()

	/**
	 * Fix one list-of-users field.
	 *
	 * @param array<int, mixed>      $values     The listed uids.
	 * @param callable(string): bool $userExists Whether a uid is a real account.
	 * @param string|null            $actingUid  Who loads the examples.
	 *
	 * @return array<int, string> Existing uids only, without duplicates.
	 */
	private function assignList(array $values, callable $userExists, ?string $actingUid): array {
		$out = [];
		foreach ($values as $value) {
			if (is_string($value) === true && $userExists($value) === true) {
				$out[] = $value;
				continue;
			}

			if ($actingUid !== null && $actingUid !== '') {
				$out[] = $actingUid;
			}
		}

		return array_values(array_unique($out));
	}//end assignList()
}//end class
