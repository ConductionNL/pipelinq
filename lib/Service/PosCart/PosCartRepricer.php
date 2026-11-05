<?php

/**
 * Pipelinq PosCartRepricer.
 *
 * Prices an open sale on the server: its lines, VAT per rate and total, for the till to show.
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
 * @spec openspec/changes/till-server-priced-lines/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\PosCart;

use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;

/**
 * Brings an open sale's stored figures up to date and returns them.
 *
 * Lines whose stored figures differ from the server's (the price display
 * changed) are stored again; the totals are PosTransactionService::recalculateTotals.
 *
 * @spec openspec/changes/till-server-priced-lines/tasks.md#task-2
 */
class PosCartRepricer {
	/**
	 * Constructor.
	 *
	 * @param PosLinePricer $pricer Prices a line; holds the sale store and the totals.
	 * @param PosCartAccess $access Whether the signed-in user may change a sale.
	 *
	 * @spec openspec/changes/till-server-priced-lines/tasks.md#task-2
	 */
	public function __construct(
		private readonly PosLinePricer $pricer,
		private readonly PosCartAccess $access,
	) {
	}//end __construct()

	/**
	 * Price an open sale and return it with its lines.
	 *
	 * @param string $transactionId The sale's id.
	 *
	 * @return array{transaction: array<string, mixed>, lines: array<int, array<string, mixed>>} The sale as the server prices it.
	 *
	 * @throws OCSNotFoundException   When the sale does not exist.
	 * @throws OCSForbiddenException  When the user may not change the sale.
	 * @throws OCSBadRequestException When the sale is no longer open.
	 *
	 * @spec openspec/changes/till-server-priced-lines/tasks.md#task-2
	 */
	public function reprice(string $transactionId): array {
		$sale = $this->pricer->objects->transaction(transactionId: $transactionId);
		$this->access->assertMayChange(sale: $sale);
		$this->repriceLines(transactionId: $transactionId, priceMode: $this->pricer->pos->normalizePriceMode(mode: ($sale['priceMode'] ?? null)));

		return [
			'transaction' => $this->pricer->pos->recalculateTotals(transactionId: $transactionId),
			'lines'       => $this->pricer->objects->lines(transactionId: $transactionId),
		];
	}//end reprice()

	/**
	 * Store again every line whose stored figures are not the server's.
	 *
	 * @param string $transactionId The sale's id.
	 * @param string $priceMode     The sale's price display.
	 *
	 * @return void
	 */
	private function repriceLines(string $transactionId, string $priceMode): void {
		foreach ($this->pricer->objects->lines(transactionId: $transactionId) as $line) {
			$figures = $this->pricer->figuresIn(line: $line, priceMode: $priceMode);
			if (self::differs(line: $line, figures: $figures) === true) {
				$this->pricer->objects->saveLine(line: array_merge($line, $figures));
			}
		}
	}//end repriceLines()

	/**
	 * Whether a stored line lacks one of the server's figures or holds another value.
	 *
	 * @param array<string, mixed> $line    The stored line.
	 * @param array<string, float> $figures The server's figures.
	 *
	 * @return bool True when the line must be stored again.
	 */
	private static function differs(array $line, array $figures): bool {
		foreach ($figures as $key => $value) {
			if (is_numeric($line[$key] ?? null) === false || (float)$line[$key] !== $value) {
				return true;
			}
		}

		return false;
	}//end differs()
}//end class
