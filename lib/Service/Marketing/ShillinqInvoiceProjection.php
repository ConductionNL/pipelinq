<?php

/**
 * Pipelinq ShillinqInvoiceProjection.
 *
 * Reduces one shillinq AR invoice row to the handful of fields pipelinq
 * reads. Pure: it takes an array and returns an array, touches no service
 * and reaches no network.
 *
 * It is separate from {@see \OCA\Pipelinq\Service\ShillinqInvoiceReader} for
 * one reason: the reader answers "how do I get shillinq's rows", and this
 * answers "which parts of a row may pipelinq look at". Keeping the second
 * question here is what lets a test assert the projection without standing
 * up an object service, and it keeps the read path free of the field list.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-bookkeeping-supplies-six-segment-fields-and-pipelinq-stores-none-of-them
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

/**
 * ShillinqInvoiceProjection: one shillinq row, reduced.
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-bookkeeping-supplies-six-segment-fields-and-pipelinq-stores-none-of-them
 */
class ShillinqInvoiceProjection {

	/**
	 * The lifecycle state of a document nobody has sent.
	 *
	 * @var string
	 */
	public const DRAFT_STATE = 'draft';

	/**
	 * Reduce a row to the whole marketing picture of one invoice.
	 *
	 * A draft is dropped. A draft invoice is a document nobody has sent, so
	 * treating it as contact with the customer would date a lapsed customer
	 * from a note somebody typed.
	 *
	 * @param array<string, mixed> $row The shillinq AR invoice.
	 *
	 * @return array<string, mixed>|null The reduced invoice, or null when the
	 *         row is a draft or carries no id.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-bookkeeping-supplies-six-segment-fields-and-pipelinq-stores-none-of-them
	 */
	public function whole(array $row): ?array {
		$state = strtolower(trim((string)($row['lifecycleState'] ?? '')));
		if ($state === '' || $state === self::DRAFT_STATE) {
			return null;
		}

		$id = $this->identify(row: $row);
		if ($id === '') {
			return null;
		}

		return [
			'id' => $id,
			'amount' => (float)($row['grossAmount'] ?? 0),
			'currency' => (string)($row['currency'] ?? 'EUR'),
			'invoiceDate' => substr((string)($row['invoiceDate'] ?? ''), 0, 10),
			'dueDate' => substr((string)($row['dueDate'] ?? ''), 0, 10),
			'lifecycleState' => $state,
			'lines' => $this->lines(value: ($row['invoiceLines'] ?? null)),
		];
	}//end whole()

	/**
	 * The canonical id of one shillinq row.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The id, empty when the row carries none.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-bookkeeping-supplies-six-segment-fields-and-pipelinq-stores-none-of-them
	 */
	public function identify(array $row): string {
		foreach ([$row, (array)($row['@self'] ?? [])] as $source) {
			foreach (['uuid', 'id', 'slug'] as $key) {
				$value = ($source[$key] ?? null);
				if (is_scalar($value) === true && (string)$value !== '') {
					return (string)$value;
				}
			}
		}

		return '';
	}//end identify()

	/**
	 * The EN 16931 invoice lines of one invoice, reduced to four fields.
	 *
	 * A line with no item name is dropped: it names nothing that could be
	 * matched against a product catalogue.
	 *
	 * @param mixed $value The raw `invoiceLines` value.
	 *
	 * @return array<int, array<string, mixed>> One entry per named line.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-bookkeeping-supplies-six-segment-fields-and-pipelinq-stores-none-of-them
	 */
	public function lines(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$lines = [];
		foreach ($value as $line) {
			if (is_array($line) === false) {
				continue;
			}

			$itemName = trim((string)($line['itemName'] ?? ''));
			if ($itemName === '') {
				continue;
			}

			$lines[] = [
				'itemName' => $itemName,
				'netAmount' => (float)($line['netAmount'] ?? 0),
				'quantity' => (float)($line['quantity'] ?? 0),
				'unitCode' => (string)($line['unitCode'] ?? ''),
			];
		}

		return $lines;
	}//end lines()
}//end class
