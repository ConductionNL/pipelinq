<?php

/**
 * Pipelinq QuoteService.
 *
 * The figures a quote sent from a lead's lines carries: the totals per VAT
 * rate, the quote number and who the quote goes to.
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
 * @spec openspec/changes/products-quote-from-lead/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Service\LineTotals\LineTotalMath;

/**
 * Totals per VAT rate, the quote number and the recipient of a quote.
 *
 * @spec openspec/changes/products-quote-from-lead/tasks.md#task-2
 */
class QuoteService {

	/**
	 * Constructor.
	 *
	 * @param ProductCatalogService $catalog The VAT rate of a product's VAT class.
	 * @param QuoteNumberSequence   $numbers The yearly OFF quote number sequence.
	 * @param LineTotalMath         $math    A line's total when it carries none.
	 */
	public function __construct(
		private readonly ProductCatalogService $catalog,
		private readonly QuoteNumberSequence $numbers,
		private readonly LineTotalMath $math,
	) {
	}//end __construct()

	/**
	 * The net amount, VAT and gross amount for each VAT rate on the lines,
	 * highest rate first, and the totals excluding and including VAT. Each
	 * line's VAT is rounded to cents before it is added up.
	 *
	 * A line's net amount is its materialised `total`, else quantity times unit
	 * price less the discount. Its rate is its own `vatRate`, else the rate of
	 * its product's VAT class (the standard rate when it has none).
	 *
	 * @param array<int, array<string, mixed>> $lines The lead's lines, each with its product when known.
	 *
	 * @return array{
	 *     rates: list<array{rate: int|float, net: float, vat: float, gross: float}>,
	 *     totalExclVat: float,
	 *     totalVat: float,
	 *     totalInclVat: float
	 * } The totals per rate and overall.
	 *
	 * @spec openspec/changes/products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-the-quote-shows-vat-per-rate-req-qslice-003
	 */
	public function vatTotals(array $lines): array {
		$groups = [];
		foreach ($lines as $line) {
			$net  = $this->lineNet(line: $line);
			$rate = $this->lineRate(line: $line);
			$key  = (string) $rate;
			if (isset($groups[$key]) === false) {
				$groups[$key] = ['rate' => $rate, 'net' => 0.0, 'vat' => 0.0];
			}

			$groups[$key]['net'] += $net;
			$groups[$key]['vat'] += round($net * (float) $rate / 100, 2);
		}

		usort($groups, static fn (array $a, array $b): int => ((float) $b['rate'] <=> (float) $a['rate']));

		$rates = [];
		$net   = 0.0;
		$vat   = 0.0;
		foreach ($groups as $group) {
			$groupNet = round($group['net'], 2);
			$groupVat = round($group['vat'], 2);
			$rates[]  = [
				'rate'  => $group['rate'],
				'net'   => $groupNet,
				'vat'   => $groupVat,
				'gross' => round($groupNet + $groupVat, 2),
			];
			$net += $groupNet;
			$vat += $groupVat;
		}

		return [
			'rates'        => $rates,
			'totalExclVat' => round($net, 2),
			'totalVat'     => round($vat, 2),
			'totalInclVat' => round($net + $vat, 2),
		];
	}//end vatTotals()

	/**
	 * The next quote number, "OFF-2026-0042". It comes from the same yearly
	 * sequence as a quote record's number, so a quote sent from a lead never
	 * repeats one; each send takes a new number.
	 *
	 * @return string The quote number.
	 *
	 * @spec openspec/changes/products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-sent-quote-does-not-change-afterwards-req-qslice-004
	 */
	public function nextNumber(): string {
		return $this->numbers->next();
	}//end nextNumber()

	/**
	 * Who the quote goes to: the lead's contact when it has an email address,
	 * else the client. Null when neither has one, and the quote cannot be sent.
	 *
	 * @param array<string, mixed>|null $contact The lead's contact.
	 * @param array<string, mixed>|null $client  The lead's client.
	 *
	 * @return array{email: string, name: string, source: string}|null The recipient.
	 *
	 * @spec openspec/changes/products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-quote-is-sent-as-a-pdf-and-logged-req-qslice-002
	 */
	public function recipient(?array $contact, ?array $client): ?array {
		foreach (['contact' => $contact, 'client' => $client] as $source => $party) {
			$email = trim((string) ($party['email'] ?? ''));
			if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
				continue;
			}

			return ['email' => $email, 'name' => self::nameOf(party: (array) $party), 'source' => $source];
		}

		return null;
	}//end recipient()

	/**
	 * A line's net amount in cents precision.
	 *
	 * @param array<string, mixed> $line The line.
	 *
	 * @return float The net amount.
	 */
	private function lineNet(array $line): float {
		if (is_numeric($line['total'] ?? null) === true) {
			return round((float) $line['total'], 2);
		}

		return $this->math->quoteLineTotal(line: $line);
	}//end lineNet()

	/**
	 * A line's VAT rate: its own, else its product's VAT class.
	 *
	 * @param array<string, mixed> $line The line.
	 *
	 * @return int|float The rate in percent.
	 */
	private function lineRate(array $line): int|float {
		if (is_numeric($line['vatRate'] ?? null) === true) {
			$rate = (float) $line['vatRate'];
			if ($rate === floor($rate)) {
				return (int) $rate;
			}

			return $rate;
		}

		$product  = ($line['product'] ?? null);
		$vatClass = null;
		if (is_array($product) === true && (string) ($product['vatClass'] ?? '') !== '') {
			$vatClass = (string) $product['vatClass'];
		}

		return $this->catalog->btwClassToRate(vatClass: $vatClass);
	}//end lineRate()

	/**
	 * How a contact or client is addressed: its name, else its first and last name.
	 *
	 * @param array<string, mixed> $party The contact or client.
	 *
	 * @return string The name; empty when it has none.
	 */
	private static function nameOf(array $party): string {
		$name = trim((string) ($party['name'] ?? ''));
		if ($name !== '') {
			return $name;
		}

		return trim((string) ($party['firstName'] ?? '').' '.(string) ($party['lastName'] ?? ''));
	}//end nameOf()
}//end class
