<?php

/**
 * Pipelinq PosLinePricer.
 *
 * Prices a sale line on the server: the only place a till line gets its VAT and total.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\PosCart
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
 * @spec openspec/changes/till-server-priced-lines/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\PosCart;

use OCA\Pipelinq\Service\PosTransactionService;

/**
 * Works out a line's VAT and total from its quantity, unit price, discount and rate, in its sale's price display.
 *
 * The arithmetic is PosTransactionService::recalculateLine, the same the confirm step books.
 *
 * @spec openspec/changes/till-server-priced-lines/tasks.md#task-1
 */
class PosLinePricer {
	/**
	 * The fields the server owns on a line.
	 */
	public const FIGURES = ['quantity', 'unitPrice', 'discount', 'taxRate', 'taxAmount', 'lineTotal'];

	/**
	 * Constructor.
	 *
	 * @param PosTransactionService $pos     Holds the VAT arithmetic and stores a sale's totals.
	 * @param PosCartObjects        $objects Reads and stores the sale and its lines.
	 *
	 * @spec openspec/changes/till-server-priced-lines/tasks.md#task-1
	 */
	public function __construct(
		public readonly PosTransactionService $pos,
		public readonly PosCartObjects $objects,
	) {
	}//end __construct()

	/**
	 * The priced figures of a line, in the price display of its sale.
	 *
	 * @param array<string, mixed> $line The line as it will be stored.
	 *
	 * @return array<string, float> The server's figures.
	 *
	 * @spec openspec/changes/till-server-priced-lines/tasks.md#task-1
	 */
	public function figures(array $line): array {
		$mode = $this->objects->priceModeOf(transactionId: (string)($line['transaction'] ?? ''));
		return $this->figuresIn(line: $line, priceMode: $mode);
	}//end figures()

	/**
	 * The priced figures of a line in a given price display.
	 *
	 * @param array<string, mixed> $line      The line.
	 * @param string|null          $priceMode The sale's price display; null falls back to excl.
	 *
	 * @return array<string, float> The server's figures.
	 *
	 * @spec openspec/changes/till-server-priced-lines/tasks.md#task-1
	 */
	public function figuresIn(array $line, ?string $priceMode): array {
		$priced = $this->pos->recalculateLine(lineData: $line, priceMode: $this->pos->normalizePriceMode(mode: $priceMode));
		return array_intersect_key($priced, array_flip(self::FIGURES));
	}//end figuresIn()
}//end class
