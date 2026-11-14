<?php

/**
 * Contract of BrpMutationWebhookListener
 *
 * The public surface of BrpMutationWebhookListener that other components call, so they depend
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
 * Contract of BrpMutationWebhookListener.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BrpMutationWebhookListenerInterface {

	/**
	 * Result code: signature mismatch.
	 */
	public const RESULT_FORBIDDEN = 'forbidden';

	/**
	 * Result code: payload was malformed / missing BSN.
	 */
	public const RESULT_BAD_REQUEST = 'bad-request';

	/**
	 * Process a single webhook delivery.
	 *
	 * @param string $rawBody The raw request body bytes (signature is computed over this).
	 * @param string $signature The `X-Signature` (or equivalent) header value (hex digest).
	 *
	 * @return array{result: string, invalidated: int}
	 *
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md
	 */
	public function handle(string $rawBody, string $signature): array;
}//end interface
