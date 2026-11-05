<?php

/**
 * Pipelinq LineTotalMath.
 *
 * Works out a quote's subtotal, VAT and total and a lead's value from their lines.
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
 * @spec openspec/changes/line-totals-roll-up/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\LineTotals;

/**
 * The arithmetic of quote and lead lines, amounts rounded to cents.
 *
 * @spec openspec/changes/line-totals-roll-up/tasks.md#task-1
 */
class LineTotalMath {
	/**
	 * A quote line's total: quantity times unit price, less the discount in percent.
	 *
	 * @param array<string, mixed> $line The quote line.
	 *
	 * @return float The line total.
	 *
	 * @spec openspec/changes/line-totals-roll-up/tasks.md#task-1
	 */
	public function quoteLineTotal(array $line): float {
		$gross    = ((float)($line['quantity'] ?? 0) * (float)($line['unitPrice'] ?? 0));
		$discount = (float)($line['discount'] ?? 0);
		return round($gross * (1 - $discount / 100), 2);
	}//end quoteLineTotal()

	/**
	 * A quote's subtotal, VAT and total.
	 *
	 * @param array<int, array<string, mixed>> $lines   The quote lines.
	 * @param float                            $vatRate The quote's VAT rate in percent.
	 *
	 * @return array{subtotal: float, vatAmount: float, total: float} The totals.
	 *
	 * @spec openspec/changes/line-totals-roll-up/tasks.md#task-1
	 */
	public function quoteTotals(array $lines, float $vatRate): array {
		$subtotal = round(array_sum(array_map(fn (array $line): float => $this->quoteLineTotal(line: $line), $lines)), 2);
		$vat      = round($subtotal * $vatRate / 100, 2);
		return ['subtotal' => $subtotal, 'vatAmount' => $vat, 'total' => round($subtotal + $vat, 2)];
	}//end quoteTotals()

	/**
	 * A lead's value: the sum of its line totals as the server calculated them.
	 *
	 * @param array<int, array<string, mixed>> $lines The lead lines.
	 *
	 * @return float The value.
	 *
	 * @spec openspec/changes/line-totals-roll-up/tasks.md#task-1
	 */
	public function leadValue(array $lines): float {
		return round(array_sum(array_map(static fn (array $line): float => (float)($line['total'] ?? 0), $lines)), 2);
	}//end leadValue()

	/**
	 * The fields a lead line change writes on its lead: always the calculated
	 * value, and the value itself unless it was typed over the calculated one.
	 *
	 * @param array<int, array<string, mixed>> $lines The lead lines.
	 * @param array<string, mixed>             $lead  The lead as stored.
	 *
	 * @return array<string, float> The fields.
	 *
	 * @spec openspec/changes/lead-use-calculated-value/tasks.md#task-1
	 */
	public function leadPatch(array $lines, array $lead): array {
		$calculated = $this->leadValue(lines: $lines);
		$patch      = ['calculatedValue' => $calculated];
		if ($this->followsLines(lead: $lead) === true) {
			$patch['value'] = $calculated;
		}

		return $patch;
	}//end leadPatch()

	/**
	 * Whether a lead's value follows its lines: it is empty or 0, no value was
	 * calculated before, or it still equals the last calculated value.
	 *
	 * @param array<string, mixed> $lead The lead as stored.
	 *
	 * @return bool True when the value follows the lines.
	 */
	private function followsLines(array $lead): bool {
		$value    = ($lead['value'] ?? null);
		$previous = ($lead['calculatedValue'] ?? null);
		if (is_numeric($value) === false || (float)$value === 0.0 || is_numeric($previous) === false) {
			return true;
		}

		return round((float)$value, 2) === round((float)$previous, 2);
	}//end followsLines()
}//end class
