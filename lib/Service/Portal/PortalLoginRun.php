<?php

/**
 * Pipelinq PortalLoginRun.
 *
 * One portal login in progress, as PortalAuthService::login builds it.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Portal
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/customer-portal/specs.md#REQ-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

/**
 * One portal login in progress: the tenant and client it comes from, and the
 * account once it is found. Built and used only by PortalAuthService::login.
 *
 * @spec openspec/changes/customer-portal/specs.md#REQ-001
 */
final class PortalLoginRun {
	/**
	 * The account record, once found (failure counters are reset on it in memory).
	 *
	 * @var array<string, mixed>
	 */
	public array $account = [];

	/**
	 * The account id, once found.
	 *
	 * @var string
	 */
	public string $accountId = '';

	/**
	 * Constructor.
	 *
	 * @param string       $tenantId The resolved tenant id.
	 * @param PortalClient $client   The client the login comes from.
	 */
	public function __construct(
		public readonly string $tenantId,
		public readonly PortalClient $client,
	) {
	}//end __construct()
}//end class
