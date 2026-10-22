<?php

/**
 * Pipelinq ResidentViewRows.
 *
 * The resident-view preview of a request ticket as labelled rows, translated
 * on the server, so a facts card draws them: what the resident portal shows,
 * what the organisation portal shows, and which fields stay internal.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
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
 * @spec openspec/changes/resident-view-as-configuration/specs/resident-view-preview/spec.md#requirement-the-server-answers-the-preview-as-labelled-rows
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\IL10N;

/**
 * Turns the resident-view panels into `{ label, value }` rows.
 *
 * @spec openspec/changes/resident-view-as-configuration/specs/resident-view-preview/spec.md#requirement-the-server-answers-the-preview-as-labelled-rows
 */
class ResidentViewRows {

	/**
	 * The resident portal's fields: preview key => label.
	 *
	 * @var array<string, string>
	 */
	private const RESIDENT_FIELDS = [
		'number' => 'Number',
		'subject' => 'Subject',
		'category' => 'Category',
		'status' => 'Status',
		'date' => 'Date',
		'body' => 'Description',
	];

	/**
	 * Ticket field labels, for the organisation portal and the internal fields.
	 *
	 * @var array<string, string>
	 */
	private const FIELD_LABELS = [
		'title' => 'Subject',
		'category' => 'Category',
		'status' => 'Status',
		'description' => 'Description',
		'occurredAt' => 'Date',
		'customerMessage' => 'Message to the customer',
		'notes' => 'Notes',
		'assignee' => 'Handler',
		'priority' => 'Priority',
	];

	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n Translates the labels.
	 */
	public function __construct(
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * What the resident portal shows: its fields, the handler unless hidden, then the messages.
	 *
	 * @param array<string, mixed>|null $bespoke The resident portal's preview, null when it shows nothing.
	 *
	 * @return array<int, array{label: string, value: string}> The rows.
	 *
	 * @spec openspec/changes/resident-view-as-configuration/specs/resident-view-preview/spec.md#requirement-the-server-answers-the-preview-as-labelled-rows
	 */
	public function residentRows(?array $bespoke): array {
		if ($bespoke === null) {
			return [];
		}

		$rows = [];
		foreach (self::RESIDENT_FIELDS as $key => $label) {
			$rows[] = $this->row(label: $label, value: ($bespoke[$key] ?? null));
		}

		if (($bespoke['assigneeHidden'] ?? false) !== true) {
			$rows[] = $this->row(label: 'Handler', value: ($bespoke['assignee'] ?? null));
		}

		foreach ((array)($bespoke['notes'] ?? []) as $note) {
			$author = 'Resident';
			if (($note['author'] ?? '') === 'handler') {
				$author = 'You';
			}

			$rows[] = $this->row(label: $author, value: ($note['message'] ?? null));
		}

		return $rows;
	}//end residentRows()

	/**
	 * What the organisation portal shows, one row per field.
	 *
	 * @param array<string, mixed>|null $portal The organisation portal's panel, null when it shows nothing.
	 *
	 * @return array<int, array{label: string, value: string}> The rows.
	 *
	 * @spec openspec/changes/resident-view-as-configuration/specs/resident-view-preview/spec.md#requirement-the-server-answers-the-preview-as-labelled-rows
	 */
	public function portalRows(?array $portal): array {
		$rows = [];
		foreach ((array)($portal['fields'] ?? []) as $field => $value) {
			$rows[] = $this->row(label: (self::FIELD_LABELS[$field] ?? (string)$field), value: $value);
		}

		return $rows;
	}//end portalRows()

	/**
	 * The fields that stay internal, as one row.
	 *
	 * @param array<int, string> $fields The internal field names.
	 *
	 * @return array<int, array{label: string, value: string}> One row, [] when nothing stays internal.
	 *
	 * @spec openspec/changes/resident-view-as-configuration/specs/resident-view-preview/spec.md#requirement-the-server-answers-the-preview-as-labelled-rows
	 */
	public function internalRows(array $fields): array {
		if ($fields === []) {
			return [];
		}

		$labels = [];
		foreach ($fields as $field) {
			$labels[] = $this->l10n->t(self::FIELD_LABELS[$field] ?? $field);
		}

		return [['label' => $this->l10n->t('Stays internal'), 'value' => implode(', ', $labels)]];
	}//end internalRows()

	/**
	 * One row, an empty value shown as a dash.
	 *
	 * @param string $label The English label.
	 * @param mixed  $value The value.
	 *
	 * @return array{label: string, value: string} The row.
	 */
	private function row(string $label, mixed $value): array {
		$text = '-';
		if ($value !== null && $value !== '' && is_scalar($value) === true) {
			$text = (string)$value;
		}

		return ['label' => $this->l10n->t($label), 'value' => $text];
	}//end row()
}//end class
