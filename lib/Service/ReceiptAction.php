<?php

/**
 * Pipelinq ReceiptAction.
 *
 * One print or email action on a POS transaction, as the receipt log records it.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * The transaction, template, action and actor of one receipt action.
 *
 * @spec openspec/specs/pos-receipt-engine/spec.md
 */
final class ReceiptAction {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $transaction The transaction with lines and invoice number.
	 * @param array<string, mixed> $template    The receipt template (empty for the default).
	 * @param string               $action      'print' or 'email'.
	 * @param string               $userId      The acting user id.
	 */
	public function __construct(
		public readonly array $transaction,
		public readonly array $template,
		public readonly string $action,
		public readonly string $userId,
	) {
	}//end __construct()
}//end class
