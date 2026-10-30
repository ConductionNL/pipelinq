<?php

/**
 * Tests for EscalationQueues and the escalated disposition's routing.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Cti
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Cti;

use InvalidArgumentException;
use OCA\Pipelinq\Service\Cti\EscalationQueues;
use OCA\Pipelinq\Service\CtiDispositionService;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Board PqGesprekAfronden: an escalated call goes to the queue the agent
 * chose ("Vergunningen, tweede lijn"); a queue routes to a Nextcloud group.
 *
 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-the-agent-chooses-the-escalation-queue-at-wrap-up
 */
class EscalationQueuesTest extends TestCase {

	private const QUEUES = '[{"id":"vergunningen","label":"Vergunningen, tweede lijn","groupId":"zuiddrecht-tweede-lijn-vergunningen"},'
		. '{"id":"belastingen","label":"Belastingen, tweede lijn","groupId":"zuiddrecht-tweede-lijn-belastingen"},'
		. '{"id":"","label":"no id"},"not a queue"]';

	/**
	 * An app config that answers the given values by key.
	 *
	 * @param array<string, string> $values The values.
	 *
	 * @return IAppConfig The config.
	 */
	private function config(array $values): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($values[$key] ?? $default)
		);
		return $config;
	}//end config()

	/**
	 * The queues are read from config; an entry without id, label or group is skipped.
	 *
	 * @return void
	 */
	public function testListsTheConfiguredQueues(): void {
		$queues = new EscalationQueues(appConfig: $this->config(['cti_escalation_queues' => self::QUEUES]));

		self::assertSame(
			[
				['id' => 'vergunningen', 'label' => 'Vergunningen, tweede lijn', 'groupId' => 'zuiddrecht-tweede-lijn-vergunningen'],
				['id' => 'belastingen', 'label' => 'Belastingen, tweede lijn', 'groupId' => 'zuiddrecht-tweede-lijn-belastingen'],
			],
			$queues->all()
		);
		self::assertSame('zuiddrecht-tweede-lijn-belastingen', $queues->find(id: 'belastingen')['groupId']);
		self::assertNull($queues->find(id: 'nope'));
	}//end testListsTheConfiguredQueues()

	/**
	 * No config, or config that is no list, is no queues.
	 *
	 * @return void
	 */
	public function testNoConfigIsNoQueues(): void {
		self::assertSame([], (new EscalationQueues(appConfig: $this->config([])))->all());
		self::assertSame([], (new EscalationQueues(appConfig: $this->config(['cti_escalation_queues' => '{oops'])))->all());
	}//end testNoConfigIsNoQueues()

	/**
	 * A disposition service whose task saves are captured.
	 *
	 * @param array<int, array<string, mixed>> $saved Collects the saved task payloads.
	 *
	 * @return CtiDispositionService The service.
	 */
	private function disposition(array &$saved): CtiDispositionService {
		$config = $this->config(['cti_escalation_queues' => self::QUEUES, 'register' => 'pipelinq', 'task_schema' => 'crmTask']);
		$objects = new class($saved) {
			/**
			 * @param array<int, array<string, mixed>> $saved Collects the payloads.
			 */
			public function __construct(private array &$saved) {
			}

			/**
			 * @param array<string, mixed> $object The payload.
			 *
			 * @return array<string, string>
			 */
			public function saveObject(array $object): array {
				$this->saved[] = $object;
				return ['id' => 'task-1'];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$tickets = $this->createMock(TicketService::class);
		$tickets->method('isConfigured')->willReturn(false);

		return new CtiDispositionService(
			container: $container,
			appConfig: $config,
			ticketService: $tickets,
			queues: new EscalationQueues(appConfig: $config),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end disposition()

	/**
	 * The chosen queue's group receives the follow-up task.
	 *
	 * @return void
	 */
	public function testAnEscalatedCallGoesToTheChosenQueuesGroup(): void {
		if (class_exists('\OCA\OpenRegister\Service\ObjectService') === false) {
			self::markTestSkipped('OpenRegister is not installed.');
		}

		$saved = [];
		$this->disposition($saved)->processDisposition(
			interactionId: 'cm-1',
			subject: 'Podium vergunning',
			outcome: 'escalated',
			notes: '',
			queue: 'vergunningen',
		);

		self::assertSame('followUpTask', $saved[0]['type']);
		self::assertSame('zuiddrecht-tweede-lijn-vergunningen', $saved[0]['assigneeGroupId']);
		self::assertArrayNotHasKey('queueName', $saved[0]);
	}//end testAnEscalatedCallGoesToTheChosenQueuesGroup()

	/**
	 * An unknown queue is refused before anything is written.
	 *
	 * @return void
	 */
	public function testAnUnknownQueueIsRefused(): void {
		$saved = [];
		$this->expectException(InvalidArgumentException::class);
		try {
			$this->disposition($saved)->processDisposition(
				interactionId: 'cm-1',
				subject: 'Podium vergunning',
				outcome: 'escalated',
				notes: '',
				queue: 'nope',
			);
		} finally {
			self::assertSame([], $saved);
		}
	}//end testAnUnknownQueueIsRefused()
}//end class
