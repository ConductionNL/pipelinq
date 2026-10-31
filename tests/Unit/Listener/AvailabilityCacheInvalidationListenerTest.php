<?php

/**
 * Unit tests for AvailabilityCacheInvalidationListener.
 *
 * A changed resource expires its own cached availability; a changed service
 * expires the resources of its required types, before and after the change;
 * other schemas are ignored; and the work is deferred, then run by the job.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/appointment-booking/spec.md#requirement-the-server-expires-cached-availability-when-a-resource-or-service-changes
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Pipelinq\BackgroundJob\DeferredObjectListenerJob;
use OCA\Pipelinq\Listener\AvailabilityCacheInvalidationListener;
use OCA\Pipelinq\Service\AvailabilityService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/RecordingDeferralService.php';
require_once __DIR__ . '/DeferredJobDrain.php';

/**
 * Tests for the availability-cache invalidation listener.
 */
class AvailabilityCacheInvalidationListenerTest extends TestCase {

	/**
	 * The availability engine.
	 *
	 * @var AvailabilityService&MockObject
	 */
	private AvailabilityService $availability;

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
		$this->deferral = new RecordingDeferralService();
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ([
				'resource_schema' => '11',
				'service_schema' => '12',
			][$key] ?? $default)
		);
		$this->listener = new AvailabilityCacheInvalidationListener(
			availability: $this->availability,
			appConfig: $appConfig,
			deferral: $this->deferral,
		);
	}//end setUp()

	/**
	 * An object of a schema.
	 *
	 * @param string $schema The schema id.
	 * @param string $uuid The uuid.
	 * @param array<string, mixed> $data The data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $schema, string $uuid, array $data = []): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setSchema($schema);
		$entity->setUuid($uuid);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * A changed resource is deferred, and the job expires that resource.
	 *
	 * @return void
	 */
	public function testAChangedResourceExpiresItsOwnCache(): void {
		$this->availability->expects($this->once())->method('invalidateResource')->with('res-1');
		$this->availability->expects($this->never())->method('invalidateForResourceTypes');

		$this->listener->handle(
			new ObjectUpdatedEvent(
				$this->entity(schema: '11', uuid: 'res-1', data: ['type' => 'room']),
				$this->entity(schema: '11', uuid: 'res-1')
			)
		);

		$this->assertSame(expected: [DeferredObjectListenerJob::class], actual: $this->deferral->jobClasses);
		$this->assertSame(expected: 'availability-cache-invalidation|res-1', actual: $this->deferral->dedupeKeys[0]);
		DeferredJobDrain::run($this, $this->deferral, $this->listener);
	}//end testAChangedResourceExpiresItsOwnCache()

	/**
	 * A created and a deleted resource expire it too.
	 *
	 * @return void
	 */
	public function testACreatedOrDeletedResourceExpiresItsCache(): void {
		$this->listener->handle(new ObjectCreatedEvent($this->entity(schema: '11', uuid: 'res-1')));
		$this->listener->handle(new ObjectDeletedEvent($this->entity(schema: '11', uuid: 'res-2')));

		$this->assertSame(expected: ['res-1', 'res-2'], actual: array_column($this->deferral->entries, 'uuid'));
		$this->assertSame(expected: ['resource', 'resource'], actual: array_column($this->deferral->entries, 'kind'));
	}//end testACreatedOrDeletedResourceExpiresItsCache()

	/**
	 * A service change expires the resources of its types before and after.
	 *
	 * @return void
	 */
	public function testAChangedServiceExpiresTheResourcesOfItsOldAndNewTypes(): void {
		$this->availability->expects($this->once())
			->method('invalidateForResourceTypes')
			->with(['room', 'staff']);

		$this->listener->handle(
			new ObjectUpdatedEvent(
				$this->entity(schema: '12', uuid: 'svc-1', data: ['requiredResourceTypes' => ['room', 'staff']]),
				$this->entity(schema: '12', uuid: 'svc-1', data: ['requiredResourceTypes' => ['room']])
			)
		);
		DeferredJobDrain::run($this, $this->deferral, $this->listener);
	}//end testAChangedServiceExpiresTheResourcesOfItsOldAndNewTypes()

	/**
	 * A service that named no type before or after runs on every resource.
	 *
	 * @return void
	 */
	public function testAServiceWithoutTypesExpiresEveryResource(): void {
		$this->listener->handle(
			new ObjectUpdatedEvent(
				$this->entity(schema: '12', uuid: 'svc-1', data: ['requiredResourceTypes' => ['room']]),
				$this->entity(schema: '12', uuid: 'svc-1', data: ['requiredResourceTypes' => []])
			)
		);
		$this->listener->handle(new ObjectCreatedEvent($this->entity(schema: '12', uuid: 'svc-2', data: ['requiredResourceTypes' => ['staff']])));

		$this->assertSame(expected: [[], ['staff']], actual: array_column($this->deferral->entries, 'resourceTypes'));
	}//end testAServiceWithoutTypesExpiresEveryResource()

	/**
	 * Any other schema, the cache schema included, is ignored.
	 *
	 * @return void
	 */
	public function testOtherSchemasAreIgnored(): void {
		$this->listener->handle(new ObjectUpdatedEvent($this->entity(schema: '99', uuid: 'cache-1'), $this->entity(schema: '99', uuid: 'cache-1')));
		$this->listener->handle(new ObjectCreatedEvent($this->entity(schema: '', uuid: 'x-1')));

		$this->assertSame(expected: [], actual: $this->deferral->entries);
	}//end testOtherSchemasAreIgnored()
}//end class
