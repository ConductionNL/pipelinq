<?php

/**
 * Contract of DeliverabilityCheckService
 *
 * The public surface of DeliverabilityCheckService that other components call, so they depend
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
 * Contract of DeliverabilityCheckService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DeliverabilityCheckServiceInterface {

	/**
	 * Load one `mailTransport` by id and return its (possibly refreshed)
	 * deliverability verdict. Controller-facing entry point so
	 * `MailTransportController` never touches `ObjectService` directly.
	 *
	 * @param string $id MailTransport UUID or slug.
	 * @param bool $forceRefresh Re-query DNS even when the cache is fresh.
	 *
	 * @return array{dkimVerified: bool, dmarcStatus: string, checkedAt: string}|null
	 *               The verdict, or null when the transport does not exist.
	 *
	 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-the-deliverability-panel-shows-spf-dkim-and-dmarc-status-per-sender-domain
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $forceRefresh is the documented
	 *  cache-bypass request, not a behaviour switch.
	 */
	public function checkTransportById(string $id, bool $forceRefresh = false): ?array;
}//end interface
