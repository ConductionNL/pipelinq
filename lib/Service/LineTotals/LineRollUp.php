<?php

/**
 * Pipelinq LineRollUp.
 *
 * When a quote or lead line changes, writes the totals of its quote or the value of its lead.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\LineTotals
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/archive/2026-11-12-line-totals-roll-up/tasks.md#task-1
 */

declare(strict_types=1);
namespace OCA\Pipelinq\Service\LineTotals;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Writes a quote's subtotal, VAT and total, or a lead's value, from every line on it.
 *
 * A lead without lines keeps the value typed by hand, and so does a lead
 * whose value was typed over the calculated one.
 *
 * @spec openspec/changes/archive/2026-11-12-line-totals-roll-up/tasks.md#task-1
 */
class LineRollUp {
	/**
	 * The property each line schema points at its parent with.
	 */
	private const PARENT_FIELD = ['salesQuoteLine' => 'quote', 'leadProduct' => 'lead'];

	/**
	 * Most lines read per parent.
	 */
	private const LIMIT = 500;

	/**
	 * The default VAT rate of a quote that names none.
	 */
	private const DEFAULT_VAT_RATE = 21.0;

	/**
	 * The arithmetic.
	 *
	 * @var LineTotalMath
	 */
	private readonly LineTotalMath $math;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objects   OpenRegister's object service.
	 * @param IAppConfig             $appConfig Holds the register and schema ids.
	 *
	 * @spec openspec/changes/archive/2026-11-12-line-totals-roll-up/tasks.md#task-1
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objects,
		private readonly IAppConfig $appConfig,
	) {
		$this->math = new LineTotalMath();
	}//end __construct()

	/**
	 * Write the totals of the quote or lead this line is on.
	 *
	 * @param ObjectEntityInterface $line The line that was created, changed or deleted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-12-line-totals-roll-up/tasks.md#task-1
	 */
	public function apply(ObjectEntityInterface $line): void {
		$parent = $this->parentOf(line: $line);
		if ($parent === null) {
			return;
		}

		$patch = $this->patchFor(parent: $parent);
		if ($patch !== []) {
			$this->objects->patchObject(objectId: $parent->parentId, data: $patch, _rbac: false, _multitenancy: false);
		}
	}//end apply()

	/**
	 * The quote or lead a line is on, or null when it is no quote or lead line.
	 *
	 * @param ObjectEntityInterface $line The line.
	 *
	 * @return LineParent|null The parent.
	 */
	private function parentOf(ObjectEntityInterface $line): ?LineParent {
		$kind = $this->kindOf(schemaId: (string)$line->getSchema());
		if ($kind === null) {
			return null;
		}

		$parentId = trim((string)($line->getObject()[self::PARENT_FIELD[$kind]] ?? ''));
		if ($parentId === '') {
			return null;
		}

		return new LineParent(kind: $kind, parentId: $parentId);
	}//end parentOf()

	/**
	 * Which line schema an object's schema is, or null.
	 *
	 * @param string $schemaId The object's schema id.
	 *
	 * @return string|null salesQuoteLine, leadProduct or null.
	 */
	private function kindOf(string $schemaId): ?string {
		foreach (array_keys(self::PARENT_FIELD) as $kind) {
			if ($schemaId !== '' && $this->schemaId(slug: $kind) === $schemaId) {
				return $kind;
			}
		}

		return null;
	}//end kindOf()

	/**
	 * The fields to write on the parent.
	 *
	 * @param LineParent $parent The parent.
	 *
	 * @return array<string, float> The totals, or nothing for a lead without lines.
	 */
	private function patchFor(LineParent $parent): array {
		$lines = $this->linesOf(parent: $parent);
		if ($parent->kind === 'leadProduct' && $lines === []) {
			return [];
		}

		if ($parent->kind === 'leadProduct') {
			return $this->math->leadPatch(lines: $lines, lead: $this->dataOf(objectId: $parent->parentId));
		}

		return $this->math->quoteTotals(lines: $lines, vatRate: $this->vatRateOf(quoteId: $parent->parentId));
	}//end patchFor()

	/**
	 * Every line on the parent.
	 *
	 * @param LineParent $parent The parent.
	 *
	 * @return array<int, array<string, mixed>> The lines.
	 */
	private function linesOf(LineParent $parent): array {
		$filters = [
			'register'                        => $this->appConfig->getValueString(Application::APP_ID, 'register', ''),
			'schema'                          => $this->schemaId(slug: $parent->kind),
			self::PARENT_FIELD[$parent->kind] => $parent->parentId,
		];
		$rows    = $this->objects->findAll(config: ['filters' => $filters, 'limit' => self::LIMIT], _rbac: false, _multitenancy: false);
		return array_values(array_map(static fn ($row): array => self::rowData(row: $row), $rows));
	}//end linesOf()

	/**
	 * A found line's data, whether OpenRegister returned an array or an object.
	 *
	 * @param mixed $row The found line.
	 *
	 * @return array<string, mixed> The line's data.
	 */
	private static function rowData(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		return (array)$row->getObject();
	}//end rowData()

	/**
	 * The VAT rate of a quote.
	 *
	 * @param string $quoteId The quote id.
	 *
	 * @return float The rate in percent.
	 */
	private function vatRateOf(string $quoteId): float {
		$rate = ($this->dataOf(objectId: $quoteId)['vatRate'] ?? null);
		if (is_numeric($rate) === false) {
			return self::DEFAULT_VAT_RATE;
		}

		return (float)$rate;
	}//end vatRateOf()

	/**
	 * A quote's or lead's data.
	 *
	 * @param string $objectId The quote or lead id.
	 *
	 * @return array<string, mixed> The data, empty when it is not found.
	 */
	private function dataOf(string $objectId): array {
		$found = $this->objects->find(id: $objectId, _rbac: false, _multitenancy: false, _render: false, _audit: false);
		return (array)($found?->getObject() ?? []);
	}//end dataOf()

	/**
	 * The schema id stored for a schema slug.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return string The id, or '' when the register was never imported.
	 */
	private function schemaId(string $slug): string {
		return $this->appConfig->getValueString(Application::APP_ID, $slug.'_schema', '');
	}//end schemaId()
}//end class
