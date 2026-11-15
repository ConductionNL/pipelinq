<?php

/**
 * One drain of a held schema: where it reads, whether it writes, and its report.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\WebForm
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/website-enquiry-intake/spec.md#requirement-held-website-messages-are-drained-into-the-destination-their-form-names
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\WebForm;

/**
 * The held register and schema, the form key property, the open filter, the apply flag,
 * and the report and form lookups the drain builds up.
 *
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/website-enquiry-intake/spec.md#requirement-held-website-messages-are-drained-into-the-destination-their-form-names
 */
final class HeldDrainRun {

	/**
	 * The report: `delivered`, `refused` and `unmapped` lists, and `pending`.
	 *
	 * @var array<string, mixed>
	 */
	public array $report = ['delivered' => [], 'refused' => [], 'unmapped' => [], 'pending' => 0];

	/**
	 * The published form found per key, null when there is none.
	 *
	 * @var array<string, array<string, mixed>|null>
	 */
	public array $forms = [];

	/**
	 * Constructor.
	 *
	 * @param string               $register    The register id or slug.
	 * @param string               $schema      The held schema id or slug.
	 * @param string               $keyProperty The property naming the form a held object came from.
	 * @param array<string, mixed> $openFilter  Property filters that select the still-open objects.
	 * @param bool                 $apply       True to submit and delete; false to report only.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The apply flag is the drain's own report-only switch.
	 */
	public function __construct(
		public readonly string $register,
		public readonly string $schema,
		public readonly string $keyProperty,
		public readonly array $openFilter,
		public readonly bool $apply,
	) {

	}//end __construct()

	/**
	 * The form key a held object names.
	 *
	 * @param array<string, mixed> $held The held object.
	 *
	 * @return string The key, or an empty string.
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/website-enquiry-intake/spec.md#requirement-held-website-messages-are-drained-into-the-destination-their-form-names
	 */
	public function keyOf(array $held): string {
		return (string)($held[$this->keyProperty] ?? '');

	}//end keyOf()

	/**
	 * Add one entry to a report list.
	 *
	 * @param string               $list  `delivered`, `refused` or `unmapped`.
	 * @param array<string, mixed> $entry The entry.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/website-enquiry-intake/spec.md#requirement-held-website-messages-are-drained-into-the-destination-their-form-names
	 */
	public function add(string $list, array $entry): void {
		$this->report[$list][] = $entry;

	}//end add()

	/**
	 * Count one held object as still held after this run.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/website-enquiry-intake/spec.md#requirement-held-website-messages-are-drained-into-the-destination-their-form-names
	 */
	public function stillHeld(): void {
		$this->report['pending']++;

	}//end stillHeld()
}//end class
