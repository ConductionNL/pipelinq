<?php

/**
 * Pipelinq PortalClient.
 *
 * The hashed IP address and user agent of the client a portal request comes from.
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
 * @spec openspec/changes/archive/2026-06-14-customer-portal/specs.md#REQ-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

/**
 * The client a portal login comes from, as the hashes the audit log and sessions keep.
 *
 * @spec openspec/changes/archive/2026-06-14-customer-portal/specs.md#REQ-001
 */
final class PortalClient {
	/**
	 * Constructor.
	 *
	 * @param string $ipHash        Hash of the client IP.
	 * @param string $userAgentHash Hash of the client user agent.
	 */
	public function __construct(
		public readonly string $ipHash,
		public readonly string $userAgentHash,
	) {
	}//end __construct()
}//end class
