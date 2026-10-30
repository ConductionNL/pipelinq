<?php

/**
 * Unit tests for ClientRiskTriggerListener: which saves recompute a client's
 * churn risk at once, and that the recompute runs in the deferred job.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Listener\ClientRiskTriggerListener;
use OCA\Pipelinq\Listener\DeferredWorkGuard;
use OCA\Pipelinq\Service\ClientRiskService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/RecordingDeferralService.php';
require_once __DIR__ . '/DeferredJobDrain.php';

/**
 * Tests for the churn risk triggers.
 *
 * @spec openspec/changes/clients-history-summary-and-risk/specs/client-insight/spec.md#requirement-every-client-carries-a-risk-level-with-its-reasons-req-cin-001
 */
class ClientRiskTriggerListenerTest extends TestCase {

	/**
	 * The clients the risk service was asked to recompute.
	 *
	 * @var list<string>
	 */
	private array $recomputed = [];

	/**
	 * The deferral double of the last-built listener.
	 *
	 * @var RecordingDeferralService|null
	 */
	private ?RecordingDeferralService $deferral = null;

	/**
	 * Clear the shared re-entrancy guard between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		DeferredWorkGuard::reset();
	}//end setUp()

	/**
	 * Build the listener.
	 *
	 * @return ClientRiskTriggerListener
	 */
	private function listener(): ClientRiskTriggerListener {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'register' => 'reg',
				'contract_schema' => 'sch-contract',
				'surveyResponse_schema' => 'sch-response',
				default => $default,
			}
		);

		$risk = $this->createMock(ClientRiskService::class);
		$risk->method('recompute')->willReturnCallback(
			function (string $clientId): bool {
				$this->recomputed[] = $clientId;
				return true;
			}
		);

		$this->deferral = new RecordingDeferralService();

		return new ClientRiskTriggerListener($risk, $appConfig, $this->deferral, $this->createMock(LoggerInterface::class));
	}//end listener()

	/**
	 * An entity of a schema.
	 *
	 * @param string $schema The schema id.
	 * @param array<string, mixed> $data The data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $schema, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('obj-1');
		$entity->setSchema($schema);
		$entity->setObject($data);

		return $entity;
	}//end entity()

	/**
	 * Application registers the listener on both object events.
	 *
	 * @return void
	 */
	public function testApplicationRegistersTheListener(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			function (string $event, string $listener, int $priority = 0) use (&$registered): void {
				$registered[] = [$event, $listener];
			}
		);

		$app = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
		$app->register($context);

		$this->assertContains([ObjectUpdatedEvent::class, ClientRiskTriggerListener::class], $registered);
		$this->assertContains([ObjectCreatedEvent::class, ClientRiskTriggerListener::class], $registered);
	}//end testApplicationRegistersTheListener()

	/**
	 * A contract that turns churned queues its client, and the deferred job recomputes it.
	 *
	 * @return void
	 */
	public function testContractStatusChangeRecomputesItsClient(): void {
		$listener = $this->listener();
		$listener->handle(
			new ObjectUpdatedEvent(
				$this->entity('sch-contract', ['status' => 'churned', 'clientRef' => 'client-1']),
				$this->entity('sch-contract', ['status' => 'active', 'clientRef' => 'client-1'])
			)
		);

		$this->assertSame([], $this->recomputed, 'Queued, not run inside the save');
		$this->assertSame(['client-risk|client-1'], $this->deferral->dedupeKeys);
		DeferredJobDrain::run($this, $this->deferral, $listener);
		$this->assertSame(['client-1'], $this->recomputed);
	}//end testContractStatusChangeRecomputesItsClient()

	/**
	 * A contract saved without a status change queues nothing.
	 *
	 * @return void
	 */
	public function testContractWithUnchangedStatusIsIgnored(): void {
		$listener = $this->listener();
		$listener->handle(
			new ObjectUpdatedEvent(
				$this->entity('sch-contract', ['status' => 'active', 'clientRef' => 'client-1', 'notes' => 'new']),
				$this->entity('sch-contract', ['status' => 'active', 'clientRef' => 'client-1'])
			)
		);

		$this->assertSame([], $this->deferral->entries);
	}//end testContractWithUnchangedStatusIsIgnored()

	/**
	 * A survey answer classified as detractor queues its client; a promoter does not.
	 *
	 * @return void
	 */
	public function testDetractorClassificationRecomputesItsClient(): void {
		$listener = $this->listener();
		$listener->handle(
			new ObjectUpdatedEvent(
				$this->entity('sch-response', ['classification' => 'detractor', 'clientRef' => 'client-2']),
				$this->entity('sch-response', ['clientRef' => 'client-2'])
			)
		);
		$listener->handle(new ObjectCreatedEvent($this->entity('sch-response', ['classification' => 'promoter', 'clientRef' => 'client-3'])));

		$this->assertSame(['client-risk|client-2'], $this->deferral->dedupeKeys);
	}//end testDetractorClassificationRecomputesItsClient()

	/**
	 * Other schemas, and a contract without a client, queue nothing.
	 *
	 * @return void
	 */
	public function testOtherSavesAreIgnored(): void {
		$listener = $this->listener();
		$listener->handle(new ObjectCreatedEvent($this->entity('sch-ticket', ['status' => 'new', 'clientRef' => 'client-1'])));
		$listener->handle(new ObjectCreatedEvent($this->entity('sch-contract', ['status' => 'active'])));

		$this->assertSame([], $this->deferral->entries);
	}//end testOtherSavesAreIgnored()
}//end class
