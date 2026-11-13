<?php

/**
 * Pipelinq PriceListResolver.
 *
 * Picks the price list entry that prices a product for a client: the client's
 * own list, else the lists for its segment, else for its country, highest
 * priority first, among active lists valid on the day and in the currency.
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
 * @spec openspec/changes/products-price-lists/specs/product-price-lists/spec.md#requirement-a-quote-line-is-priced-from-the-matching-list-req-ppl-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;

/**
 * Chooses a price list price; pure, it reads nothing itself.
 *
 * @spec openspec/changes/products-price-lists/specs/product-price-lists/spec.md#requirement-a-quote-line-is-priced-from-the-matching-list-req-ppl-002
 */
class PriceListResolver {

	/**
	 * The price list price of a product for a client, or null when no list prices it.
	 *
	 * Within one list the entry with the highest minimum quantity at most the
	 * quantity wins. An entry for the requested variant goes before one for the
	 * product itself; without a variant only product entries count. Lists of
	 * equal priority are tried newest `validFrom` first, then lowest id.
	 *
	 * @param array<int, array<string, mixed>> $lists      Every price list.
	 * @param array<string, mixed>             $client     The client the line is for.
	 * @param string                           $productId  The product's id.
	 * @param string|null                      $variantSku The variant, if any.
	 * @param float                            $quantity   The quantity.
	 * @param string                           $currency   The quote's currency.
	 * @param DateTimeImmutable                $day        The day the price is for.
	 *
	 * @return array{unitPrice: float, priceListId: string, priceListTitle: string, minQuantity: float}|null
	 *
	 * @spec openspec/changes/products-price-lists/specs/product-price-lists/spec.md#requirement-a-quote-line-is-priced-from-the-matching-list-req-ppl-002
	 */
	public function select(
		array $lists,
		array $client,
		string $productId,
		?string $variantSku,
		float $quantity,
		string $currency,
		DateTimeImmutable $day
	): ?array {
		if ($productId === '') {
			return null;
		}

		$usable = array_values(
			array_filter(
				$lists,
				fn (array $list): bool => $this->isUsable(list: $list, currency: $currency, day: $day)
			)
		);

		return self::firstMatch(
			lists: $this->candidates(lists: $usable, client: $client),
			productId: $productId,
			sku: trim((string) $variantSku),
			quantity: $quantity
		);
	}//end select()

	/**
	 * The answer of the first list, in order, with an entry that prices the line.
	 *
	 * @param list<array<string, mixed>> $lists     The candidate lists, in order.
	 * @param string                     $productId The product's id.
	 * @param string                     $sku       The variant SKU; empty for none.
	 * @param float                      $quantity  The quantity.
	 *
	 * @return array{unitPrice: float, priceListId: string, priceListTitle: string, minQuantity: float}|null
	 */
	private static function firstMatch(array $lists, string $productId, string $sku, float $quantity): ?array {
		foreach ($lists as $list) {
			$entry = self::entryFor(list: $list, productId: $productId, sku: $sku, quantity: $quantity);
			if ($entry !== []) {
				return self::matchOf(list: $list, entry: $entry);
			}
		}

		return null;
	}//end firstMatch()

	/**
	 * The answer for a list entry that prices the line.
	 *
	 * @param array<string, mixed> $list  The list.
	 * @param array<string, mixed> $entry The entry of that list.
	 *
	 * @return array{unitPrice: float, priceListId: string, priceListTitle: string, minQuantity: float}
	 */
	private static function matchOf(array $list, array $entry): array {
		return [
			'unitPrice'      => round(max(0.0, (float) $entry['unitPrice']), 2),
			'priceListId'    => self::idOf(list: $list),
			'priceListTitle' => (string) ($list['title'] ?? ''),
			'minQuantity'    => self::minQuantity(entry: $entry),
		];
	}//end matchOf()

	/**
	 * The lists to try, in order: the client's own, then its segment's, then its country's.
	 *
	 * @param array<int, array<string, mixed>> $lists  The usable lists.
	 * @param array<string, mixed>             $client The client.
	 *
	 * @return list<array<string, mixed>> The lists in the order they are tried.
	 */
	private function candidates(array $lists, array $client): array {
		$ordered = self::ownLists(lists: $lists, ownId: self::referenceId(value: ($client['priceList'] ?? null)));

		$segment = self::normalise(value: ($client['segment'] ?? ''));
		$country = self::normalise(value: ($client['country'] ?? ''));
		foreach ([['segments', $segment], ['countries', $country]] as [$field, $value]) {
			array_push($ordered, ...self::listsFor(lists: $lists, field: $field, value: $value));
		}

		return $ordered;
	}//end candidates()

	/**
	 * The lists whose id is the client's own list; none when it names none.
	 *
	 * @param array<int, array<string, mixed>> $lists The usable lists.
	 * @param string                           $ownId The client's list id, or empty.
	 *
	 * @return list<array<string, mixed>> The client's own lists.
	 */
	private static function ownLists(array $lists, string $ownId): array {
		if ($ownId === '') {
			return [];
		}

		return array_values(
			array_filter($lists, static fn (array $list): bool => self::idOf(list: $list) === $ownId)
		);
	}//end ownLists()

	/**
	 * The lists naming a segment or country, best first; none for an empty value.
	 *
	 * @param array<int, array<string, mixed>> $lists The usable lists.
	 * @param string                           $field segments or countries.
	 * @param string                           $value The client's normalised value.
	 *
	 * @return list<array<string, mixed>> The matching lists, ordered.
	 */
	private static function listsFor(array $lists, string $field, string $value): array {
		if ($value === '') {
			return [];
		}

		$matching = array_values(
			array_filter(
				$lists,
				static fn (array $list): bool => in_array($value, array_map([self::class, 'normalise'], (array) ($list[$field] ?? [])), true)
			)
		);
		usort($matching, [self::class, 'compareLists']);

		return $matching;
	}//end listsFor()

	/**
	 * Whether a list may price anything: active, in the currency and valid on the day.
	 *
	 * @param array<string, mixed> $list     The list.
	 * @param string               $currency The quote's currency.
	 * @param DateTimeImmutable    $day      The day.
	 *
	 * @return bool True when it may.
	 */
	private function isUsable(array $list, string $currency, DateTimeImmutable $day): bool {
		if (($list['isActive'] ?? true) === false) {
			return false;
		}

		if (strtoupper(trim((string) ($list['currency'] ?? 'EUR'))) !== strtoupper(trim($currency))) {
			return false;
		}

		return self::isValidOn(list: $list, day: $day);
	}//end isUsable()

	/**
	 * Whether the day falls within a list's validFrom and validUntil, either open.
	 *
	 * @param array<string, mixed> $list The list.
	 * @param DateTimeImmutable    $day  The day.
	 *
	 * @return bool True when valid on the day.
	 */
	private static function isValidOn(array $list, DateTimeImmutable $day): bool {
		$date = $day->format('Y-m-d');
		$from = substr((string) ($list['validFrom'] ?? ''), 0, 10);
		$till = substr((string) ($list['validUntil'] ?? ''), 0, 10);
		if ($from !== '' && $from > $date) {
			return false;
		}

		return ($till === '' || $till >= $date);
	}//end isValidOn()

	/**
	 * The entry of a list that prices the product at the quantity: one for
	 * the variant first, else one for the product itself; empty when none.
	 *
	 * @param array<string, mixed> $list      The list.
	 * @param string               $productId The product's id.
	 * @param string               $sku       The variant SKU; empty for none.
	 * @param float                $quantity  The quantity.
	 *
	 * @return array<string, mixed> The entry; empty when none.
	 */
	private static function entryFor(array $list, string $productId, string $sku, float $quantity): array {
		$skus = [''];
		if ($sku !== '') {
			$skus = [$sku, ''];
		}

		foreach ($skus as $candidate) {
			$best = self::bestEntry(list: $list, productId: $productId, sku: $candidate, quantity: $quantity);
			if ($best !== []) {
				return $best;
			}
		}

		return [];
	}//end entryFor()

	/**
	 * Among a list's entries for the product and exact SKU at the quantity,
	 * the one with the highest minimum quantity; the first such on a tie.
	 *
	 * @param array<string, mixed> $list      The list.
	 * @param string               $productId The product's id.
	 * @param string               $sku       The variant SKU; empty for the product itself.
	 * @param float                $quantity  The quantity.
	 *
	 * @return array<string, mixed> The entry; empty when none.
	 */
	private static function bestEntry(array $list, string $productId, string $sku, float $quantity): array {
		$best = [];
		foreach ((array) ($list['entries'] ?? []) as $entry) {
			$entry = (array) $entry;
			if (self::prices(entry: $entry, productId: $productId, sku: $sku, quantity: $quantity) === false) {
				continue;
			}

			if ($best === [] || self::minQuantity(entry: $entry) > self::minQuantity(entry: $best)) {
				$best = $entry;
			}
		}

		return $best;
	}//end bestEntry()

	/**
	 * Whether an entry prices the product and exact SKU at the quantity.
	 *
	 * @param array<string, mixed> $entry     The entry.
	 * @param string               $productId The product's id.
	 * @param string               $sku       The variant SKU; empty for the product itself.
	 * @param float                $quantity  The quantity.
	 *
	 * @return bool True when it does.
	 */
	private static function prices(array $entry, string $productId, string $sku, float $quantity): bool {
		return self::referenceId(value: ($entry['product'] ?? null)) === $productId
			&& trim((string) ($entry['variantSku'] ?? '')) === $sku
			&& is_numeric($entry['unitPrice'] ?? null) === true
			&& self::minQuantity(entry: $entry) <= $quantity;
	}//end prices()

	/**
	 * Order lists highest priority first, then newest validFrom, then lowest id.
	 *
	 * @param array<string, mixed> $a A list.
	 * @param array<string, mixed> $b Another list.
	 *
	 * @return int The comparison.
	 */
	private static function compareLists(array $a, array $b): int {
		return [(int) ($b['priority'] ?? 0), (string) ($b['validFrom'] ?? ''), self::idOf(list: $a)]
			<=> [(int) ($a['priority'] ?? 0), (string) ($a['validFrom'] ?? ''), self::idOf(list: $b)];
	}//end compareLists()

	/**
	 * An entry's minimum quantity; 1 when it names none.
	 *
	 * @param array<string, mixed> $entry The entry.
	 *
	 * @return float The minimum quantity.
	 */
	private static function minQuantity(array $entry): float {
		if (is_numeric($entry['minQuantity'] ?? null) === true) {
			return (float) $entry['minQuantity'];
		}

		return 1.0;
	}//end minQuantity()

	/**
	 * A list's id.
	 *
	 * @param array<string, mixed> $list The list.
	 *
	 * @return string The id; empty when it has none.
	 */
	private static function idOf(array $list): string {
		return (string) ($list['id'] ?? ($list['@self']['id'] ?? ($list['uuid'] ?? '')));
	}//end idOf()

	/**
	 * The id a reference holds: the string itself, or the id of an expanded object.
	 *
	 * @param mixed $value The reference.
	 *
	 * @return string The id; empty when there is none.
	 */
	private static function referenceId(mixed $value): string {
		if (is_array($value) === true) {
			return self::idOf(list: $value);
		}

		if (is_string($value) === true) {
			return trim($value);
		}

		return '';
	}//end referenceId()

	/**
	 * A segment or country as compared: trimmed and lower case.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The normalised value.
	 */
	private static function normalise(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return strtolower(trim($value));
	}//end normalise()
}//end class
