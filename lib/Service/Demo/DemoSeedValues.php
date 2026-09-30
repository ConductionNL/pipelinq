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
	 * Resolve `@days:N` / `@datetime:N` placeholders to concrete dates.
	 *
	 * @param array<string, mixed> $data Raw definition data.
	 *
	 * @return array<string, mixed> Data with date placeholders resolved.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 */
	public function resolvePlaceholders(array $data): array {
		foreach ($data as $field => $value) {
			if (is_string($value) === false) {
				continue;
			}

			if (preg_match('/^@(days|datetime):(-?\d+)$/', $value, $matches) !== 1) {
				continue;
			}

			$offset = (int)$matches[2];
			$moment = (new DateTimeImmutable())->modify(sprintf('%+d days', $offset));

			$data[$field] = $moment->format(DateTimeInterface::ATOM);
			if ($matches[1] === 'days') {
				$data[$field] = $moment->format('Y-m-d');
			}
		}

		return $data;
	}//end resolvePlaceholders()

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
}//end class
