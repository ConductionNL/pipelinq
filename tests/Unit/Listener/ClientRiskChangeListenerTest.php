<?php

/**
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Pipelinq\Listener\ClientRiskChangeListener;
use OCA\Pipelinq\Service\ClientRiskService;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A contract status change or a detractor answer recomputes its client at once.
 *
 * @covers \OCA\Pipelinq\Listener\ClientRiskChangeListener
 *
 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-1.3
 */
class ClientRiskChangeListenerTest extends TestCase {

	/**
	 * The clients recomputed.
	 *
	 * @var array<int, string>
	 */
	private array $recomputed = [];

	/**
	 * The listener over contract schema "k" and survey answer schema "s".
	 *
	 * @param bool $fails Whether the recompute throws.
	 *
	 * @return ClientRiskChangeListener
	 */
	private function listener(bool $fails = false): ClientRiskChangeListener {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (['contract_schema' => 'k', 'surveyResponse_schema' => 's'][$key] ?? $default)
		);
		$risk = $this->createMock(ClientRiskService::class);
		$risk->method('recompute')->willReturnCallback(function (string $clientId) use ($fails): bool {
			if ($fails === true) {
				throw new RuntimeException('down');
			}

			$this->recomputed[] = $clientId;
			return true;
		});

		return new ClientRiskChangeListener($config, $risk, $this->createMock(LoggerInterface::class));
	}//end listener()

	/**
	 * An entity of the given schema and data.
	 *
	 * @param string               $schema The schema id.
	 * @param array<string, mixed> $data   The object data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $schema, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('o-1');
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * A contract that turns churned recomputes its client.
	 *
	 * @return void
	 */
	public function testAContractStatusChangeRecomputesItsClient(): void {
		$this->listener()->handle(new ObjectUpdatedEvent(
			$this->entity('k', ['clientRef' => 'c-1', 'status' => 'churned']),
			$this->entity('k', ['clientRef' => 'c-1', 'status' => 'expiring'])
		));

		$this->assertSame(['c-1'], $this->recomputed);
	}//end testAContractStatusChangeRecomputesItsClient()

	/**
	 * An answer classified a detractor recomputes its client, on create as on update.
	 *
	 * @return void
	 */
	public function testADetractorAnswerRecomputesItsClient(): void {
		$this->listener()->handle(new ObjectCreatedEvent($this->entity('s', ['clientRef' => 'c-2', 'classification' => 'detractor'])));
		$this->listener()->handle(new ObjectUpdatedEvent(
			$this->entity('s', ['clientRef' => 'c-3', 'classification' => 'detractor']),
			$this->entity('s', ['clientRef' => 'c-3'])
		));

		$this->assertSame(['c-2', 'c-3'], $this->recomputed);
	}//end testADetractorAnswerRecomputesItsClient()

	/**
	 * Nothing is recomputed when nothing that counts changed.
	 *
	 * @return void
	 */
	public function testNothingIsRecomputedWhenNothingChanged(): void {
		$listener = $this->listener();
		foreach ([
			new ObjectUpdatedEvent($this->entity('k', ['clientRef' => 'c-1', 'status' => 'active', 'notes' => 'x']), $this->entity('k', ['clientRef' => 'c-1', 'status' => 'active'])),
			new ObjectUpdatedEvent($this->entity('s', ['clientRef' => 'c-1', 'classification' => 'detractor', 'verbatim' => 'x']), $this->entity('s', ['clientRef' => 'c-1', 'classification' => 'detractor'])),
			new ObjectCreatedEvent($this->entity('s', ['clientRef' => 'c-1', 'classification' => 'promoter'])),
			new ObjectUpdatedEvent($this->entity('client', ['status' => 'churned']), $this->entity('client', ['status' => 'active'])),
			new ObjectUpdatedEvent($this->entity('k', ['status' => 'churned']), $this->entity('k', ['status' => 'active'])),
			new Event(),
		] as $event) {
			$listener->handle($event);
		}

		$this->assertSame([], $this->recomputed);
	}//end testNothingIsRecomputedWhenNothingChanged()

	/**
	 * A failed recompute never reaches the save that triggered it.
	 *
	 * @return void
	 */
	public function testAFailedRecomputeIsSwallowed(): void {
		$this->listener(fails: true)->handle(new ObjectCreatedEvent($this->entity('k', ['clientRef' => 'c-1', 'status' => 'churned'])));

		$this->assertSame([], $this->recomputed);
	}//end testAFailedRecomputeIsSwallowed()
}//end class
