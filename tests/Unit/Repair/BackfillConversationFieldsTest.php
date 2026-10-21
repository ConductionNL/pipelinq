<?php

/**
 * Unit tests for BackfillConversationFields and ChannelMessageClientListener.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Listener\ChannelMessageClientListener;
use OCA\Pipelinq\Repair\BackfillConversationFields;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A legacy answer reaches the thread; a message names its contact's client.
 */
class BackfillConversationFieldsTest extends TestCase {

	private array $patches = [];

	public function testALegacyAnswerIsAddedToTheAnswers(): void {
		$patch = BackfillConversationFields::answerPatch(ticket: ['customerMessage' => 'Answer', 'portalAnswers' => [], '@self' => ['updated' => '2026-09-01T10:00:00+00:00']]);

		$this->assertSame(['portalAnswers' => [['message' => 'Answer', 'createdAt' => '2026-09-01T10:00:00+00:00']]], $patch);
	}

	public function testAnAnswerAlreadyKeptIsLeftAlone(): void {
		$this->assertSame([], BackfillConversationFields::answerPatch(ticket: ['customerMessage' => 'Answer', 'portalAnswers' => [['message' => 'Answer']]]));
		$this->assertSame([], BackfillConversationFields::answerPatch(ticket: ['customerMessage' => '']));
	}

	public function testTheRepairPatchesTicketsAndMessages(): void {
		$rows = [
			'sch-ticket' => [['id' => 't-1', 'customerMessage' => 'Answer'], ['id' => 't-2']],
			'sch-message' => [['id' => 'm-1', 'contactId' => 'c-1'], ['id' => 'm-2', 'contactId' => 'c-1', 'clientId' => 'k-1']],
		];
		$objectService = $this->objectService(rows: $rows);
		$repair = new BackfillConversationFields($objectService, $this->appConfig(), new ChannelMessageClientListener($objectService, $this->appConfig()), $this->createMock(LoggerInterface::class));

		$repair->run($this->createMock(IOutput::class));

		$this->assertSame(['t-1', 'm-1'], array_column($this->patches, 'id'));
		$this->assertSame(['clientId' => 'k-1'], $this->patches[1]['data']);
	}

	public function testAMessageIsStampedWithItsContactsClientBeforeTheSave(): void {
		$listener = new ChannelMessageClientListener($this->objectService(rows: []), $this->appConfig());
		$entity = $this->entity(['contactId' => 'c-1', 'body' => 'Hi']);
		$event = new ObjectCreatingEvent($entity);

		$listener->handle($event);

		$this->assertSame(['clientId' => 'k-1'], $event->getModifiedData());
	}

	public function testAnUnchangedContactWithAClientIsLeftAlone(): void {
		$listener = new ChannelMessageClientListener($this->objectService(rows: []), $this->appConfig());
		$event = new ObjectUpdatingEvent($this->entity(['contactId' => 'c-1', 'clientId' => 'k-9']), $this->entity(['contactId' => 'c-1']));

		$listener->handle($event);

		$this->assertSame([], $event->getModifiedData());
	}

	public function testApplicationRegistersTheListenerAndInfoXmlTheRepair(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(function (string $event, string $listener) use (&$registered): void {
			$registered[] = [$event, $listener];
		});
		$app = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
		$app->register($context);

		$this->assertContains([ObjectCreatingEvent::class, ChannelMessageClientListener::class], $registered);
		$this->assertContains([ObjectUpdatingEvent::class, ChannelMessageClientListener::class], $registered);
		$this->assertStringContainsString('BackfillConversationFields', (string)file_get_contents(__DIR__ . '/../../../appinfo/info.xml'));
	}

	private function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('m-1');
		$entity->setSchema('sch-message');
		$entity->setObject($data);
		return $entity;
	}

	private function objectService(array $rows): ObjectServiceInterface {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(static fn (array $config = []): array => ($rows[$config['filters']['schema']] ?? []));
		$objectService->method('find')->willReturnCallback(function (int|string $id): ?ObjectEntity {
			if ($id !== 'c-1') {
				return null;
			}
			$entity = new ObjectEntity();
			$entity->setObject(['client' => 'k-1']);
			return $entity;
		});
		$objectService->method('patchObject')->willReturnCallback(function (string $objectId, array $data): ObjectEntity {
			$this->patches[] = ['id' => $objectId, 'data' => $data];
			return new ObjectEntity();
		});
		return $objectService;
	}

	private function appConfig(): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => ([
			'register' => 'reg', 'ticket_schema' => 'sch-ticket', 'message_schema' => 'sch-message', 'contact_schema' => 'sch-contact',
		][$key] ?? $default));
		return $appConfig;
	}
}
