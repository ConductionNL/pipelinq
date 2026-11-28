<?php

/**
 * Pipelinq SentQuote.
 *
 * A quote as it was rendered: its number, validity, total and PDF.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Quote
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
 * @spec openspec/changes/archive/2026-11-25-products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-sent-quote-does-not-change-afterwards-req-qslice-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Quote;

/**
 * The rendered quote. Once made it is not changed: sending again makes a new one.
 *
 * @spec openspec/changes/archive/2026-11-25-products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-sent-quote-does-not-change-afterwards-req-qslice-004
 */
final class SentQuote {

	/**
	 * Constructor.
	 *
	 * @param string      $number       The quote number, "OFF-2026-0042".
	 * @param string      $validUntil   The last day the quote holds, Y-m-d.
	 * @param float       $totalInclVat The total including VAT.
	 * @param string      $content      The PDF bytes.
	 * @param string      $filename     The PDF's file name, with extension.
	 * @param int|null    $fileId       The id of the copy in the sender's Files.
	 * @param string|null $path         The path of that copy.
	 */
	public function __construct(
		public readonly string $number,
		public readonly string $validUntil,
		public readonly float $totalInclVat,
		public readonly string $content,
		public readonly string $filename,
		public readonly ?int $fileId,
		public readonly ?string $path,
	) {
	}//end __construct()
}//end class
