<?php

/**
 * Pipelinq LeadLinePricer.
 *
 * Decides the unit price of a lead's product line on the server: from the
 * client's price lists through the catalogue's resolver, or kept as typed.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\LinePricing
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
 * @spec openspec/changes/products-price-lists/tasks.md#task-1.4
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\LinePricing;

use OCA\Pipelinq\Kernel\Contracts\CallerObjectReaderInterface;
use OCA\Pipelinq\Kernel\Contracts\ProductCatalogServiceInterface;

/**
 * The price a lead line is stored with.
 *
 * A new line without a price asks the resolver, with the lead's client and
 * currency. A line whose price was typed keeps it and is marked as set by hand;
 * a quantity change then does not replace it. A line priced by the server is
 * priced again when its quantity, product or variant changes.
 *
 * @spec openspec/changes/products-price-lists/specs/product-price-lists/spec.md#requirement-a-quote-line-is-priced-from-the-matching-list-req-ppl-002
 */
class LeadLinePricer {
	/**
	 * The source written on a line whose price was typed.
	 */
	public const SOURCE_MANUAL = 'manual';

	/**
	 * The fields whose change makes a server-priced line ask again.
	 */
	private const PRICE_INPUTS = ['product', 'quantity', 'variantSku'];

	/**
	 * Constructor.
	 *
	 * @param ProductCatalogServiceInterface $catalog The price resolver.
	 * @param CallerObjectReaderInterface    $reader  Reads the product, lead and client with the caller's rights.
	 *
	 * @spec openspec/changes/products-price-lists/tasks.md#task-1.4
	 */
	public function __construct(
		private readonly ProductCatalogServiceInterface $catalog,
		private readonly CallerObjectReaderInterface $reader,
	) {
	}//end __construct()

	/**
	 * The price fields to store on a line, or [] to store it as it is.
	 *
	 * @param array<string, mixed>      $line The line as it will be stored.
	 * @param array<string, mixed>|null $old  The stored line, or null for a new one.
	 *
	 * @return array<string, mixed> unitPrice, priceSource and priceListTitle, or a subset, or [].
	 *
	 * @spec openspec/changes/products-price-lists/specs/product-price-lists/spec.md#requirement-a-quote-line-is-priced-from-the-matching-list-req-ppl-002
	 * @spec openspec/changes/products-price-lists/specs/product-price-lists/spec.md#requirement-a-typed-price-stays-req-ppl-003
	 */
	public function figures(array $line, ?array $old): array {
		if (self::filled(value: $line['product'] ?? null) === false) {
			return [];
		}

		if ($old === null) {
			if (self::filled(value: $line['unitPrice'] ?? null) === true && $this->isOwnPrice(line: $line) === false) {
				return self::byHand();
			}

			return $this->resolved(line: $line);
		}

		return $this->changed(line: $line, old: $old);
	}//end figures()

	/**
	 * The price fields of an edited line.
	 *
	 * @param array<string, mixed> $line The line as it will be stored.
	 * @param array<string, mixed> $old  The stored line.
	 *
	 * @return array<string, mixed> The fields to write, or [].
	 */
	private function changed(array $line, array $old): array {
		$typed = self::filled(value: $line['unitPrice'] ?? null) === true
			&& self::sameNumber(left: $line['unitPrice'], right: $old['unitPrice'] ?? null) === false
			&& $this->isOwnPrice(line: $line) === false;
		if ($typed === true) {
			return self::byHand();
		}

		if (($line['priceSource'] ?? $old['priceSource'] ?? null) === self::SOURCE_MANUAL) {
			return [];
		}

		foreach (self::PRICE_INPUTS as $field) {
			if (self::sameValue(left: $line[$field] ?? null, right: $old[$field] ?? null) === false) {
				return $this->resolved(line: $line);
			}
		}

		return [];
	}//end changed()

	/**
	 * Whether the line's price is the product's own price: the price stored
	 * on the product, which the line form copies when a product is picked,
	 * or the product's price for the line's quantity and variant. Such a
	 * price was not typed, so the line is priced as the server would, quantity
	 * tier and client price list included (products-quote-from-lead task 1).
	 *
	 * @param array<string, mixed> $line The line.
	 *
	 * @return bool True when the price equals the product's own price.
	 *
	 * @spec openspec/changes/products-quote-from-lead/tasks.md
	 */
	private function isOwnPrice(array $line): bool {
		$product = $this->reader->read(id: (string)$line['product'], schemaKey: 'product_schema');
		if ($product === null) {
			return false;
		}

		if (self::sameNumber(left: $line['unitPrice'] ?? null, right: $product['unitPrice'] ?? null) === true) {
			return true;
		}

		$own = $this->catalog->resolveEffectivePrice(
			product: $product,
			quantity: self::quantityOf(line: $line),
			variantSku: self::textOrNull(value: $line['variantSku'] ?? null),
			client: null,
			currency: null
		);

		return self::sameNumber(left: $line['unitPrice'] ?? null, right: $own['unitPrice'] ?? null);
	}//end isOwnPrice()

	/**
	 * Ask the resolver for the line's price.
	 *
	 * @param array<string, mixed> $line The line.
	 *
	 * @return array<string, mixed> The price fields, or [] when the product cannot be read.
	 */
	private function resolved(array $line): array {
		$product = $this->reader->read(id: (string)$line['product'], schemaKey: 'product_schema');
		if ($product === null) {
			return [];
		}

		$lead     = $this->readOptional(id: $line['lead'] ?? null, schemaKey: 'lead_schema');
		$client   = $this->readOptional(id: $lead['client'] ?? null, schemaKey: 'client_schema');
		$price    = $this->catalog->resolveEffectivePrice(
			product: $product,
			quantity: self::quantityOf(line: $line),
			variantSku: self::textOrNull(value: $line['variantSku'] ?? null),
			client: $client,
			currency: self::textOrNull(value: $lead['currency'] ?? null)
		);

		return [
			'unitPrice'      => $price['unitPrice'] ?? null,
			'priceSource'    => (string)($price['source'] ?? 'base'),
			'priceListTitle' => $price['priceListTitle'] ?? null,
		];
	}//end resolved()

	/**
	 * Read an object when an id is given.
	 *
	 * @param mixed  $id        The id, or anything empty.
	 * @param string $schemaKey The app config key of its schema.
	 *
	 * @return array<string, mixed>|null The object, or null.
	 */
	private function readOptional(mixed $id, string $schemaKey): ?array {
		if (self::filled(value: $id) === false || is_scalar($id) === false) {
			return null;
		}

		return $this->reader->read(id: (string)$id, schemaKey: $schemaKey);
	}//end readOptional()

	/**
	 * The line's quantity, 1 when it holds none.
	 *
	 * @param array<string, mixed> $line The line.
	 *
	 * @return float The quantity.
	 */
	private static function quantityOf(array $line): float {
		if (is_numeric($line['quantity'] ?? null) === false) {
			return 1.0;
		}

		return (float)$line['quantity'];
	}//end quantityOf()

	/**
	 * A value as text, or null when it is empty.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The text, or null.
	 */
	private static function textOrNull(mixed $value): ?string {
		if (self::filled(value: $value) === false || is_scalar($value) === false) {
			return null;
		}

		return (string)$value;
	}//end textOrNull()

	/**
	 * The fields of a price set by hand.
	 *
	 * @return array<string, mixed> The source and an empty list title.
	 */
	private static function byHand(): array {
		return ['priceSource' => self::SOURCE_MANUAL, 'priceListTitle' => null];
	}//end byHand()

	/**
	 * Whether a value holds something.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool False for null and the empty string.
	 */
	private static function filled(mixed $value): bool {
		return $value !== null && $value !== '';
	}//end filled()

	/**
	 * Whether two prices are the same number.
	 *
	 * @param mixed $left  One price.
	 * @param mixed $right The other.
	 *
	 * @return bool True when both are numeric and equal to the cent.
	 */
	private static function sameNumber(mixed $left, mixed $right): bool {
		if (is_numeric($left) === false || is_numeric($right) === false) {
			return false;
		}

		return abs((float)$left - (float)$right) < 0.005;
	}//end sameNumber()

	/**
	 * Whether two input values are the same, numbers compared as numbers.
	 *
	 * @param mixed $left  One value.
	 * @param mixed $right The other.
	 *
	 * @return bool True when they are equal.
	 */
	private static function sameValue(mixed $left, mixed $right): bool {
		if (is_numeric($left) === true && is_numeric($right) === true) {
			return (float)$left === (float)$right;
		}

		return $left === $right;
	}//end sameValue()
}//end class
