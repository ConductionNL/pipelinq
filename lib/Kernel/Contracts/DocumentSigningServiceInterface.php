<?php

/**
 * Contract of DocumentSigningService
 *
 * The public surface of DocumentSigningService that other components call, so they depend
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
 * Contract of DocumentSigningService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DocumentSigningServiceInterface {

	/**
	 * Default token TTL in minutes.
	 *
	 * @var int
	 */
	public const DEFAULT_TTL_MINUTES = 5;

	/**
	 * Generate a signed download token for an object, valid for $ttlMinutes.
	 *
	 * @param string $objectId The object id.
	 * @param string $objectType The object type (e.g. invoice).
	 * @param string $accountId The requesting account id (re-checked on download).
	 * @param int $ttlMinutes The TTL in minutes.
	 *
	 * @return array{token: string, path: string, expiresAt: int} The signed material.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function generateUrl(
		string $objectId,
		string $objectType,
		string $accountId,
		int $ttlMinutes = self::DEFAULT_TTL_MINUTES,
	): array;

	/**
	 * Validate a token: verify the signature, then the expiry.
	 *
	 * Returns the decoded payload when valid, or a typed failure marker the
	 * caller maps to the right status: null = invalid (404), 'expired' = expired
	 * (410 Gone) so a legitimately-issued-but-stale link is distinguishable.
	 *
	 * @param string $token The presented token.
	 *
	 * @return array<string, mixed>|string|null The payload, 'expired', or null.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function validateToken(string $token): array|string|null;
}//end interface
