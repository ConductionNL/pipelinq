<?php

/**
 * Contract of TrackingLinkService
 *
 * The public surface of TrackingLinkService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

/**
 * Contract of TrackingLinkService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TrackingLinkServiceInterface {

	/**
	 * Record a click on a BlastDelivery — delegates the click semantics to
	 * {@see AttributionService::recordClick()} (set-once `firstClickAt`,
	 * deduped `clickedUrls`, status advance), then refreshes the per-blast
	 * totals roll-up, then reports the click to the configured Portaliq
	 * portal as an `email_click` traffic event.
	 *
	 * @param string $blastDeliveryId BlastDelivery UUID or slug.
	 * @param string $url The clicked (decoded) target URL.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-07-12-marketing-email-open-click-tracking/tasks.md#1.3
	 * @spec openspec/specs/marketing-email-tracking/spec.md#requirement-opens-and-clicks-are-reported-to-portaliq-as-email-traffic-events
	 */
	public function recordClick(string $blastDeliveryId, string $url): void;

	/**
	 * Record an open on a BlastDelivery — set-once `openedAt`, advance
	 * `status` toward `opened` (never downgrading a terminal status), then
	 * refresh the per-blast totals roll-up, then report the open to the
	 * configured Portaliq portal as an `email_open` traffic event.
	 *
	 * @param string $blastDeliveryId BlastDelivery UUID or slug.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-07-12-marketing-email-open-click-tracking/tasks.md#1.3
	 * @spec openspec/specs/marketing-email-tracking/spec.md#requirement-opens-and-clicks-are-reported-to-portaliq-as-email-traffic-events
	 */
	public function recordOpen(string $blastDeliveryId): void;

	/**
	 * Verify a token issued by `signOpenToken()` / `signClickToken()`.
	 *
	 * Constant-time signature comparison via `hash_equals`; fails closed
	 * (returns null) on a malformed token, a signature mismatch, or an
	 * expired token. Never throws.
	 *
	 * @param string $token The presented token.
	 *
	 * @return array<string, mixed>|null Decoded payload (`d`, `u`, `iat`,
	 *                                   `exp`) or null when invalid/expired.
	 *
	 * @spec openspec/changes/archive/2026-07-12-marketing-email-open-click-tracking/tasks.md#1.1
	 */
	public function verifyToken(string $token): ?array;
}//end interface
