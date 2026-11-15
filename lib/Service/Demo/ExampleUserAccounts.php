<?php

/**
 * Pipelinq ExampleUserAccounts.
 *
 * The accounts an example record's user fields may point at: the real users
 * of this server, with the person who loads the examples standing in for a
 * demo name.
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

use Closure;

/**
 * Points the user fields of one example record at existing users.
 *
 * @spec openspec/specs/example-data/spec.md#requirement-every-example-record-imports
 */
final class ExampleUserAccounts {

	/**
	 * Constructor.
	 *
	 * @param Closure(string): bool $userExists Whether a uid is a real account.
	 * @param string|null           $actingUid  Who loads the examples, or null.
	 *
	 * @spec openspec/specs/example-data/spec.md#requirement-every-example-record-imports
	 */
	public function __construct(
		private readonly Closure $userExists,
		private readonly ?string $actingUid,
	) {
	}//end __construct()

	/**
	 * Point every user field of a record at an existing user, or leave it out.
	 *
	 * @param array<string, mixed> $object     The record.
	 * @param array<mixed, mixed>  $properties The properties of the record's schema.
	 *
	 * @return array<string, mixed> The record with real users only.
	 *
	 * @spec openspec/specs/example-data/spec.md#requirement-every-example-record-imports
	 */
	public function assignFields(array $object, array $properties): array {
		foreach ($properties as $key => $property) {
			if (array_key_exists($key, $object) === false || is_array($property) === false) {
				continue;
			}

			if ($this->isUserProperty(property: $property) === true) {
				$object = $this->assignOne(object: $object, key: $key);
				continue;
			}

			if (($property['type'] ?? '') === 'array'
				&& $this->isUserProperty(property: (array)($property['items'] ?? [])) === true
				&& is_array($object[$key]) === true
			) {
				$object[$key] = $this->assignList(values: $object[$key]);
			}
		}

		return $object;
	}//end assignFields()

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
	 * @param array<string, mixed> $object The record.
	 * @param string               $key    The field.
	 *
	 * @return array<string, mixed> The record.
	 */
	private function assignOne(array $object, string $key): array {
		$value = $object[$key];
		if ($value === null || $value === '' || (is_string($value) === true && ($this->userExists)($value) === true)) {
			return $object;
		}

		if ($this->actingUid === null || $this->actingUid === '') {
			unset($object[$key]);
			return $object;
		}

		$object[$key] = $this->actingUid;
		return $object;
	}//end assignOne()

	/**
	 * Fix one list-of-users field.
	 *
	 * @param array<int, mixed> $values The listed uids.
	 *
	 * @return array<int, string> Existing uids only, without duplicates.
	 */
	private function assignList(array $values): array {
		$out = [];
		foreach ($values as $value) {
			if (is_string($value) === true && ($this->userExists)($value) === true) {
				$out[] = $value;
				continue;
			}

			if ($this->actingUid !== null && $this->actingUid !== '') {
				$out[] = $this->actingUid;
			}
		}

		return array_values(array_unique($out));
	}//end assignList()
}//end class
