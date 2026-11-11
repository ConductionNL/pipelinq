<?php

/**
 * Pipelinq PortalActor.
 *
 * The portal account that performs an action and the tenant it acts in.
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
 * The account id and tenant id an audited portal action is recorded under.
 *
 * @spec openspec/changes/customer-portal/specs.md#REQ-001
 */
final class PortalActor {
	/**
	 * Constructor.
	 *
	 * @param string $accountId The portal account id.
	 * @param string $tenantId  The tenant id.
	 */
	public function __construct(
		public readonly string $accountId,
		public readonly string $tenantId,
	) {
	}//end __construct()
}//end class
