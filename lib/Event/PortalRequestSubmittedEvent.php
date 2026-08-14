<?php

/**
 * Pipelinq PortalRequestSubmittedEvent.
 *
 * Emitted when a customer submits a request through the portal so the SLA
 * engine / omnichannel inbox can react (start the SLA clock, surface the
 * request in the medewerker inbox as channel `portal`) without the portal
 * depending on those subsystems directly (REQ-006).
 *
 * @category Event
 * @package  OCA\Pipelinq\Event
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/customer-portal/specs.md#REQ-006
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Event;

use OCP\EventDispatcher\Event;

/**
 * Fired when a portal-submitted request is created.
 */
class PortalRequestSubmittedEvent extends Event {
	/**
	 * Constructor.
	 *
	 * @param string $requestId The created request id.
	 * @param string $tenantId The tenant id.
	 */
	public function __construct(
		private string $requestId,
		private string $tenantId,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The created request id.
	 *
	 * @return string The request id.
	 */
	public function getRequestId(): string {
		return $this->requestId;
	}//end getRequestId()

	/**
	 * The tenant id.
	 *
	 * @return string The tenant id.
	 */
	public function getTenantId(): string {
		return $this->tenantId;
	}//end getTenantId()
}//end class
