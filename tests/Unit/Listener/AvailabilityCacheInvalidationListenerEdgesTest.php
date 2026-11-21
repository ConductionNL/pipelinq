<?php

/**
 * The edges of AvailabilityCacheInvalidationListener: foreign events and
 * malformed resource-type lists.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/appointment-booking/spec.md#requirement-the-server-expires-cached-availability-when-a-resource-or-service-changes
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\Pipelinq\Listener\AvailabilityCacheInvalidationListener;
use OCA\Pipelinq\Service\AvailabilityService;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/RecordingDeferralService.php';

/**
 * An event that carries no object reads no config and defers nothing; a
 * service whose requiredResourceTypes is not a list defers an empty type list;
 * deferred service work whose resourceTypes is not a list expires every
 * resource; deferred work of an unknown kind expires nothing.
 *
 * @covers \OCA\Pipelinq\Listener\AvailabilityCacheInvalidationListener
 */
class AvailabilityCacheInvalidationListenerEdgesTest extends TestCase {

	/**
	 * The availability engine.
	 *
	 * @var AvailabilityService&MockObject
	 */
	private AvailabilityService $availability;

	/**
	 * The app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * The recorded deferrals.
	 *
	 * @var RecordingDeferralService
	 */
	private RecordingDeferralService $deferral;

	/**
	 * The listener under test.
	 *
	 * @var AvailabilityCacheInvalidationListener
	 */
	private AvailabilityCacheInvalidationListener $listener;

	/**
	 * Build the listener over the resource and service schema ids.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->availability = $this->createMock(originalClassName: AvailabilityService::class);
		$this->deferral     = new RecordingDeferralService();
		$this->appConfig    = $this->createMock(originalClassName: IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ([
				'resource_schema' => '11',
				'service_schema' => '12',
			][$key] ?? $default)
		);
		$this->listener = new AvailabilityCacheInvalidationListener(
			availability: $this->availability,
			appConfig: $this->appConfig,
			deferral: $this->deferral,
		);
	}//end setUp()

	/**
	 * A plain event is ignored before any config is read.
	 *
	 * @return void
	 */
	public function testAnEventWithoutAnObjectIsIgnored(): void {
		$this->appConfig->expects($this->never())->method('getValueString');

		$this->listener->handle(new Event());

		$this->assertSame(expected: [], actual: $this->deferral->entries);
	}//end testAnEventWithoutAnObjectIsIgnored()

	/**
	 * A created service whose requiredResourceTypes is a string defers an
	 * empty type list under its dedupe key.
	 *
	 * @return void
	 */
	public function testAScalarRequiredResourceTypesDefersNoTypes(): void {
		$entity = new ObjectEntity();
		$entity->setSchema('12');
		$entity->setUuid('svc-3');
		$entity->setObject(['requiredResourceTypes' => 'room']);

		$this->listener->handle(new ObjectCreatedEvent($entity));

		$this->assertSame(
			expected: [['handler' => 'availability-cache-invalidation', 'uuid' => 'svc-3', 'kind' => 'service', 'resourceTypes' => []]],
			actual: $this->deferral->entries
		);
		$this->assertSame(expected: ['availability-cache-invalidation|svc-3'], actual: $this->deferral->dedupeKeys);
	}//end testAScalarRequiredResourceTypesDefersNoTypes()

	/**
	 * Deferred service work whose resourceTypes is not a list expires the
	 * resources of every type.
	 *
	 * @return void
	 */
	public function testDeferredServiceWorkWithScalarTypesExpiresEveryType(): void {
		$this->availability->expects($this->once())->method('invalidateForResourceTypes')->with([]);
		$this->availability->expects($this->never())->method('invalidateResource');

		$this->listener->runDeferredWork(['uuid' => 'svc-4', 'kind' => 'service', 'resourceTypes' => 'room']);
	}//end testDeferredServiceWorkWithScalarTypesExpiresEveryType()

	/**
	 * Deferred work without a known kind expires nothing.
	 *
	 * @return void
	 */
	public function testDeferredWorkOfAnUnknownKindExpiresNothing(): void {
		$this->availability->expects($this->never())->method('invalidateForResourceTypes');
		$this->availability->expects($this->never())->method('invalidateResource');

		$this->listener->runDeferredWork(['uuid' => 'x-1']);
		$this->listener->runDeferredWork(['uuid' => 'x-2', 'kind' => 'cache']);
	}//end testDeferredWorkOfAnUnknownKindExpiresNothing()
}//end class
