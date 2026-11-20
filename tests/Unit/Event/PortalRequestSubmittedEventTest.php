<?php

/**
 * Unit tests for the PortalRequestSubmittedEvent domain event.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Event;

use OCA\Pipelinq\Event\PortalRequestSubmittedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Event\PortalRequestSubmittedEvent
 */
class PortalRequestSubmittedEventTest extends TestCase {

	/**
	 * The request and tenant ids are kept apart, as the service dispatches them by name.
	 *
	 * @return void
	 */
	public function testKeepsRequestAndTenantApart(): void {
		$event = new PortalRequestSubmittedEvent(requestId: 'req-7', tenantId: 'tenant-a');

		$this->assertSame(['req-7', 'tenant-a'], [$event->getRequestId(), $event->getTenantId()]);
	}//end testKeepsRequestAndTenantApart()

	/**
	 * A positional construction follows the same order: request first, tenant second.
	 *
	 * @return void
	 */
	public function testPositionalOrderIsRequestThenTenant(): void {
		$event = new PortalRequestSubmittedEvent('req-8', 'tenant-b');

		$this->assertSame('req-8', $event->getRequestId());
		$this->assertSame('tenant-b', $event->getTenantId());
	}//end testPositionalOrderIsRequestThenTenant()

	/**
	 * It is a Nextcloud event that starts propagating and can be stopped by a listener.
	 *
	 * @return void
	 */
	public function testIsAStoppableNextcloudEvent(): void {
		$event = new PortalRequestSubmittedEvent(requestId: '', tenantId: '');

		$this->assertInstanceOf(Event::class, $event);
		$this->assertFalse($event->isPropagationStopped());
		$event->stopPropagation();
		$this->assertTrue($event->isPropagationStopped());
	}//end testIsAStoppableNextcloudEvent()
}//end class
