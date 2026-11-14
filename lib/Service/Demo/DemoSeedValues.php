<?php

/**
 * Pipelinq DemoSeedValues.
 *
 * The value transforms every demo seeder applies to a seed-file row before it
 * is saved: date placeholders resolved to concrete dates, and seed-file keys
 * resolved to the uuids of objects already seeded.
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
 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Demo;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Placeholder and reference resolution shared by the demo seeders.
 *
 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
 */
class DemoSeedValues {
	/**
	 * Marker prefix carried by every seeded object's lookup field. Nothing
	 * without it is ever deleted.
	 *
	 * @var string
	 */
	public const DEMO_PREFIX = '[Demo]';

	/**
	 * Resolve `@days:N` / `@datetime:N` / `@week:D[THH:MM]` placeholders to
	 * concrete dates, in nested lists and objects too.
	 *
	 * `@days:N` and `@datetime:N` are N days from now, as a date and as a
	 * moment. `@week:D` is day D (0 is Monday, 6 is Sunday) of the current
	 * ISO week as a date, `@week:DTHH:MM` that day at that time: a booking
	 * seeded with it always lands in the week the seed runs, whatever day
	 * that is.
	 *
	 * @param array<string, mixed> $data Raw definition data.
	 *
	 * @return array<string, mixed> Data with date placeholders resolved.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 * @spec openspec/changes/services-and-resources-at-a-glance/tasks.md#4-example-data
	 */
	public function resolvePlaceholders(array $data): array {
		foreach ($data as $field => $value) {
			if (is_array($value) === true) {
				$data[$field] = $this->resolvePlaceholders(data: $value);
				continue;
			}

			if (is_string($value) === false) {
				continue;
			}

			$data[$field] = $this->resolveOne(value: $value);
		}

		return $data;
	}//end resolvePlaceholders()

	/**
	 * Resolve one placeholder string, or return the value unchanged.
	 *
	 * @param string $value The candidate placeholder.
	 *
	 * @return string The resolved date, moment, or the value itself.
	 *
	 * @spec openspec/changes/services-and-resources-at-a-glance/tasks.md#4-example-data
	 */
	private function resolveOne(string $value): string {
		if (preg_match('/^@week:([0-6])(?:T([01]\d|2[0-3]):([0-5]\d))?$/', $value, $week) === 1) {
			$day = (new DateTimeImmutable('monday this week'))->modify(sprintf('+%d days', (int)$week[1]));
			if (isset($week[2]) === false) {
				return $day->format('Y-m-d');
			}

			return $day->setTime((int)$week[2], (int)$week[3])->format(DateTimeInterface::ATOM);
		}

		if (preg_match('/^@(days|datetime):(-?\d+)$/', $value, $matches) !== 1) {
			return $value;
		}

		$moment = (new DateTimeImmutable())->modify(sprintf('%+d days', (int)$matches[2]));
		if ($matches[1] === 'days') {
			return $moment->format('Y-m-d');
		}

		return $moment->format(DateTimeInterface::ATOM);
	}//end resolveOne()

	/**
	 * Set one relation field from the seeded uuid map, when the definition
	 * names a key and that key has already been seeded.
	 *
	 * @param array<string, mixed> $data Definition data.
	 * @param array<string, mixed> $definition Full definition.
	 * @param array<string, string> $uuids Already-seeded uuid map (section:key => uuid).
	 * @param string $keyName Definition key naming the target (e.g. 'clientKey').
	 * @param string $section Uuid-map section the key lives in (e.g. 'clients').
	 * @param string $field Field on $data to set.
	 *
	 * @return array<string, mixed> Data with the relation field set when resolvable.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 */
	public function linkReference(array $data, array $definition, array $uuids, string $keyName, string $section, string $field): array {
		if (isset($definition[$keyName]) === false) {
			return $data;
		}

		$uuid = ($uuids[$section . ':' . $definition[$keyName]] ?? null);
		if ($uuid !== null) {
			$data[$field] = $uuid;
		}

		return $data;
	}//end linkReference()

	/**
	 * Set a relation field on every entry of a list field from the seeded
	 * uuid map, the way linkReference() does for one top-level field.
	 *
	 * Each entry names its target under `$keyName`; that key is a seed-file
	 * key, never stored, so it is removed whether or not it resolves.
	 *
	 * @param array<string, mixed>  $data      Definition data.
	 * @param string                $listField Field on $data holding the list (e.g. 'resourceAssignments').
	 * @param array<string, string> $uuids     Already-seeded uuid map (section:key => uuid).
	 * @param array{0: string, 1: string, 2: string} $link The entry key name, the uuid-map section and the field to set.
	 *
	 * @return array<string, mixed> Data with each entry's relation field set when resolvable.
	 *
	 * @spec openspec/changes/services-and-resources-at-a-glance/tasks.md#4-example-data
	 */
	public function linkListReferences(array $data, string $listField, array $uuids, array $link): array {
		if (is_array($data[$listField] ?? null) === false) {
			return $data;
		}

		[$keyName, $section, $field] = $link;
		foreach ($data[$listField] as $i => $entry) {
			if (is_array($entry) === false || isset($entry[$keyName]) === false) {
				continue;
			}

			$uuid = ($uuids[$section . ':' . $entry[$keyName]] ?? null);
			unset($entry[$keyName]);
			if ($uuid !== null) {
				$entry[$field] = $uuid;
			}

			$data[$listField][$i] = $entry;
		}

		return $data;
	}//end linkListReferences()
}//end class
