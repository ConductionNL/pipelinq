<?php

/**
 * Pipelinq ReceiptLines.
 *
 * The lines of a receipt context: the BTW lines from the server-computed
 * breakdown and the item lines of the transaction.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/pos-receipt-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\IL10N;

/**
 * The BTW and item lines of one receipt; built by ReceiptService.
 *
 * @spec openspec/specs/pos-receipt-engine/spec.md
 */
final class ReceiptLines {
	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n Translates the rate label of a BTW line without a description.
	 */
	public function __construct(
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The BTW lines of the receipt from the server-computed breakdown; a row
	 * without a description is labelled by its rate.
	 *
	 * @param mixed $vatRows The invoiceBreakdown (or taxBreakdown) rows.
	 *
	 * @return array<int, array{rate: float, base: float, tax: float, label: string}>
	 *
	 * @spec openspec/specs/pos-receipt-engine/spec.md
	 */
	public function vatLines($vatRows): array {
		if (is_array($vatRows) === false) {
			return [];
		}

		$vatLines = [];
		foreach ($vatRows as $row) {
			if (is_array($row) === true) {
				$vatLines[] = $this->vatLineOf(row: $row);
			}
		}

		return $vatLines;
	}//end vatLines()


	/**
	 * One BTW line: rate, base, tax and its label.
	 *
	 * @param array<string, mixed> $row One breakdown row.
	 *
	 * @return array{rate: float, base: float, tax: float, label: string}
	 */
	private function vatLineOf(array $row): array {
		$rate = (float)($row['rate'] ?? 0);
		$label = (string)($row['description'] ?? '');
		if ($label === '') {
			$label = rtrim(rtrim((string)$rate, '0'), '.') . '% ' . $this->l10n->t('VAT');
		}

		return [
			'rate' => $rate,
			'base' => (float)($row['base'] ?? 0),
			'tax' => (float)($row['tax'] ?? 0),
			'label' => $label,
		];
	}//end vatLineOf()


	/**
	 * The item lines of the receipt: description, quantity and line total.
	 *
	 * @param mixed $lines The transaction lines.
	 *
	 * @return array<int, array{description: string, quantity: float, lineTotal: float}>
	 *
	 * @spec openspec/specs/pos-receipt-engine/spec.md
	 */
	public function itemLines($lines): array {
		$out = [];
		foreach ($lines as $line) {
			if (is_array($line) === false) {
				continue;
			}

			$out[] = [
				'description' => (string)($line['description'] ?? ''),
				'quantity' => (float)($line['quantity'] ?? 0),
				'lineTotal' => (float)($line['lineTotal'] ?? 0),
			];
		}

		return $out;
	}//end itemLines()
}//end class
