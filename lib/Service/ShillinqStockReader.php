<?php

/**
 * Pipelinq ShillinqStockReader.
 *
 * Reads how many of one product shillinq keeps, summed over its locations,
 * so a salesperson sees what is left before promising it.
 *
 * READ ONLY. Shillinq owns the count (ADR-022: the owner keeps the data);
 * pipelinq stores no copy and writes no movement here. The read runs as the
 * logged-in user, with OpenRegister's checks on, so a user who may not read
 * shillinq's stock gets `no-access` instead of a number. The probe is a
 * string constant and the path fails soft when shillinq is absent.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/product-stock/spec.md#requirement-the-product-page-shows-the-available-stock-req-pst-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Kernel\Contracts\ShillinqStockReaderInterface;
use OCA\Pipelinq\Util\SerialisedToArray;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * ShillinqStockReader: the stock of one product in shillinq.
 *
 * @spec openspec/specs/product-stock/spec.md#requirement-the-product-page-shows-the-available-stock-req-pst-001
 */
class ShillinqStockReader implements ShillinqStockReaderInterface {
	use SerialisedToArray;

	/**
	 * Shillinq's register slug.
	 *
	 * @var string
	 */
	public const SHILLINQ_REGISTER = 'shillinq';

	/**
	 * The stock schema slug shillinq declares.
	 *
	 * @var string
	 */
	public const STOCK_SCHEMA = 'InventoryStock';

	/**
	 * A class that exists exactly when shillinq is installed.
	 *
	 * @var string
	 */
	public const SHILLINQ_PROBE_CLASS = 'OCA\\Shillinq\\AppInfo\\Application';

	/**
	 * Never read more locations than this for one product.
	 *
	 * @var int
	 */
	public const MAX_LOCATIONS = 50;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container for the lazy, duck-typed object service.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The stock of one product, summed over every shillinq location.
	 *
	 * Shillinq keys a stock row on `productId`; its seeded rows carry only
	 * `productSku`. So the read asks for `productId` first and, when that finds
	 * nothing, for the product's SKU.
	 *
	 * @param string $productId The product's `productId`, the key shillinq stock is kept on.
	 * @param string $sku The product's SKU, matched against `productSku`.
	 *
	 * @return array{state: string, available: float, onHand: float, reserved: float,
	 *     locations: array<int, array{code: string, name: string, available: float}>}
	 *         `state` is `ok`, `no-shillinq` or `no-access`.
	 *
	 * @spec openspec/specs/product-stock/spec.md#requirement-the-product-page-shows-the-available-stock-req-pst-001
	 */
	public function stockFor(string $productId, string $sku = ''): array {
		$objectService = $this->reachableObjectService();
		if ($objectService === null) {
			return $this->answer(state: 'no-shillinq');
		}

		return $this->firstStock(objectService: $objectService, keys: $this->keys(productId: $productId, sku: $sku));
	}//end stockFor()

	/**
	 * The stock under the first key that finds a location, else the last
	 * (empty) sum; no access as soon as a read is refused.
	 *
	 * @param object                $objectService The object service.
	 * @param array<string, string> $keys          Field => key, in reading order.
	 *
	 * @return array{state: string, available: float, onHand: float, reserved: float,
	 *     locations: array<int, array{code: string, name: string, available: float}>}
	 */
	private function firstStock(object $objectService, array $keys): array {
		$answer = $this->answer(state: 'ok');
		foreach ($keys as $field => $key) {
			$rows = $this->rowsFor(objectService: $objectService, filter: [$field => $key]);
			if ($rows === null) {
				return $this->answer(state: 'no-access');
			}

			$answer = $this->sum(rows: $rows, field: $field, key: $key);
			if ($answer['locations'] !== []) {
				return $answer;
			}
		}

		return $answer;
	}//end firstStock()

	/**
	 * The object service when shillinq is installed and OpenRegister answers, else null.
	 *
	 * @return object|null The service.
	 */
	private function reachableObjectService(): ?object {
		if ($this->probe() === false) {
			return null;
		}

		return $this->objectService();
	}//end reachableObjectService()

	/**
	 * The keys to read on, in order: the trimmed `productId`, then the trimmed
	 * `productSku`, leaving out an empty one.
	 *
	 * @param string $productId The product's `productId`.
	 * @param string $sku       The product's SKU.
	 *
	 * @return array<string, string> Field => key.
	 */
	private function keys(string $productId, string $sku): array {
		return array_filter(
			['productId' => trim($productId), 'productSku' => trim($sku)],
			static fn (string $key): bool => $key !== ''
		);
	}//end keys()

	/**
	 * The stock rows matching one key, read as the user; null when the read is refused.
	 *
	 * @param object               $objectService The object service.
	 * @param array<string,string> $filter        The one field => key to read on.
	 *
	 * @return iterable<mixed>|null
	 */
	private function rowsFor(object $objectService, array $filter): ?iterable {
		try {
			$rows = $objectService->findAll(config: $this->query(filter: $filter));
		} catch (Throwable $e) {
			$this->logger->info(
				'ShillinqStockReader: the user cannot read shillinq stock',
				['product' => current($filter), 'exception' => $e->getMessage()]
			);
			return null;
		}

		return $this->iterable(value: $rows);
	}//end rowsFor()

	/**
	 * The findAll() config for shillinq stock rows on one field => key.
	 *
	 * @param array<string, string> $filter The one field => key.
	 *
	 * @return array<string, mixed>
	 */
	private function query(array $filter): array {
		return [
			'filters' => ['register' => self::SHILLINQ_REGISTER, 'schema' => self::STOCK_SCHEMA, ...$filter],
			'limit' => self::MAX_LOCATIONS,
		];
	}//end query()

	/**
	 * A findAll() answer as an iterable.
	 *
	 * @param mixed $value The answer.
	 *
	 * @return iterable<mixed>
	 */
	private function iterable(mixed $value): iterable {
		if (is_iterable($value) === true) {
			return $value;
		}

		return [];
	}//end iterable()

	/**
	 * Whether shillinq's application class is loadable. Protected so a
	 * test can substitute the answer without shillinq present.
	 *
	 * @return bool True when shillinq is installed.
	 *
	 * @spec openspec/specs/product-stock/spec.md#requirement-untracked-products-and-missing-stock-read-plainly-req-pst-002
	 */
	protected function probe(): bool {
		return class_exists(self::SHILLINQ_PROBE_CLASS);
	}//end probe()

	/**
	 * Sum the rows of this product. A row for another product is dropped,
	 * because OpenRegister ignores a filter key it does not recognise and
	 * would then hand back every stock row.
	 *
	 * @param iterable<mixed> $rows The rows read.
	 * @param string $field The field the read filtered on.
	 * @param string $key The value it filtered for.
	 *
	 * @return array{state: string, available: float, onHand: float, reserved: float,
	 *     locations: array<int, array{code: string, name: string, available: float}>}
	 */
	private function sum(iterable $rows, string $field, string $key): array {
		$answer = $this->answer(state: 'ok');
		foreach ($rows as $value) {
			$row = $this->toArray(value: $value);
			if ((string)($row[$field] ?? '') !== $key) {
				continue;
			}

			$location = $this->location(row: $row);
			foreach (['onHand', 'reserved', 'available'] as $figure) {
				$answer[$figure] += $location[$figure];
			}

			$answer['locations'][] = ['code' => $location['code'], 'name' => $location['name'], 'available' => $location['available']];
		}

		return $answer;
	}//end sum()

	/**
	 * One stock row's figures: on hand, reserved, and available (the row's own
	 * figure when numeric, else on hand minus reserved), with its location.
	 *
	 * @param array<string, mixed> $row The stock row.
	 *
	 * @return array{onHand: float, reserved: float, available: float, code: string, name: string}
	 */
	private function location(array $row): array {
		$onHand = (float)($row['quantityOnHand'] ?? 0);
		$reserved = (float)($row['quantityReserved'] ?? 0);
		$available = ($onHand - $reserved);
		if (is_numeric($row['quantityAvailable'] ?? null) === true) {
			$available = (float)$row['quantityAvailable'];
		}

		return [
			'onHand' => $onHand,
			'reserved' => $reserved,
			'available' => $available,
			'code' => (string)($row['locationCode'] ?? ''),
			'name' => (string)($row['locationName'] ?? ($row['locationCode'] ?? '')),
		];
	}//end location()

	/**
	 * An empty answer in one state.
	 *
	 * @param string $state The state.
	 *
	 * @return array{state: string, available: float, onHand: float, reserved: float,
	 *     locations: array<int, array{code: string, name: string, available: float}>}
	 */
	private function answer(string $state): array {
		return ['state' => $state, 'available' => 0.0, 'onHand' => 0.0, 'reserved' => 0.0, 'locations' => []];
	}//end answer()

	/**
	 * The OpenRegister object service, or null when it cannot be built.
	 *
	 * @return object|null The service.
	 */
	private function objectService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'ShillinqStockReader: OpenRegister unavailable',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end objectService()
}//end class
