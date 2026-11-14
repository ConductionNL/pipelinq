<?php

/**
 * Contract of PortalMfaService
 *
 * The public surface of PortalMfaService that other components call, so they depend
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
 * Contract of PortalMfaService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalMfaServiceInterface {

	/**
	 * Encrypt a secret for storage on the account record.
	 *
	 * @param string $secret The base32 secret.
	 *
	 * @return string The ciphertext.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function encryptSecret(string $secret): string;

	/**
	 * Generate a new base32 TOTP secret (160 bits).
	 *
	 * @return string The base32-encoded secret.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function generateSecret(): string;

	/**
	 * Build the otpauth:// provisioning URI the client renders as a QR code.
	 *
	 * @param string $secret The base32 secret.
	 * @param string $accountLabel The account label (usually the email).
	 * @param string $issuer The issuer name shown in the authenticator.
	 *
	 * @return string The otpauth URI.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function provisioningUri(string $secret, string $accountLabel, string $issuer): string;

	/**
	 * Verify a presented 6-digit code against an encrypted stored secret.
	 *
	 * Accepts the current step plus +/- one step of skew. Returns false on any
	 * decryption error or malformed code (fail-closed).
	 *
	 * @param string|null $encryptedSecret The encrypted base32 secret.
	 * @param string|null $code The presented code.
	 *
	 * @return bool True when the code is valid for the current time window.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function verifyCode(?string $encryptedSecret, ?string $code): bool;
}//end interface
