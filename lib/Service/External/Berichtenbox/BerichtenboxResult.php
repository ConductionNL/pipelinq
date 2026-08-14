<?php

/**
 * Result value-object returned by a Pipelinq Berichtenbox adapter call.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\External\Berichtenbox
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\External\Berichtenbox;

/**
 * Result of a Berichtenbox dispatch / webhook-verify / mailbox-check
 * attempt.
 *
 * `outcome` is one of `DISPATCHED`, `WEBHOOK_VERIFIED`,
 * `MAILBOX_ACTIVE`, `MAILBOX_NOT_FOUND`, `DISPATCH_DEFERRED`,
 * `VERIFY_DEFERRED`, `MAILBOX_DEFERRED`, `BERICHTENBOX_ERROR`.
 * `DISPATCH_DEFERRED` / `VERIFY_DEFERRED` / `MAILBOX_DEFERRED` are
 * dormant defaults so callers can branch on the prefix.
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md
 */
final class BerichtenboxResult {
	/**
	 * Construct the result value-object.
	 *
	 * @param string $outcome Outcome enum.
	 * @param string $logiusReference Logius-side
	 *                              conversation
	 *                              / dispatch
	 *                              handle
	 *                              (synthetic
	 *                              for
	 *                              dormant).
	 * @param bool $dormant TRUE when the
	 *                      adapter was
	 *                      dormant.
	 * @param array<string,mixed> $extras Provider-specific
	 *                                    extras —
	 *                                    deliveryStatus,
	 *                                    deliveredAt,
	 *                                    signatureVerified,
	 *                                    mailboxLastUsedAt.
	 */
	public function __construct(
		public readonly string $outcome,
		public readonly string $logiusReference,
		public readonly bool $dormant,
		public readonly array $extras = [],
	) {
	}//end __construct()
}//end class
