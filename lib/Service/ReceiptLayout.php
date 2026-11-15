<?php

/**
 * Pipelinq ReceiptLayout.
 *
 * The width and variant a plain-text receipt is rendered in.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/pos-receipt-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * The layout width of a receipt and whether it is the legal-invoice variant.
 *
 * @spec openspec/specs/pos-receipt-engine/spec.md
 */
final class ReceiptLayout {
	/**
	 * Constructor.
	 *
	 * @param int  $width   The layout width in characters.
	 * @param bool $invoice Whether this is an invoice.
	 */
	public function __construct(
		public readonly int $width,
		public readonly bool $invoice,
	) {
	}//end __construct()

	/**
	 * A left label and a right-aligned value on one line, at least one space apart.
	 *
	 * @param string $left  The left label.
	 * @param string $right The right value.
	 *
	 * @return string The row.
	 *
	 * @spec openspec/specs/pos-receipt-engine/spec.md
	 */
	public function row(string $left, string $right): string {
		$space = ($this->width - mb_strlen($left) - mb_strlen($right));
		if ($space < 1) {
			$space = 1;
		}

		return $left . str_repeat(' ', $space) . $right;
	}//end row()
}//end class
