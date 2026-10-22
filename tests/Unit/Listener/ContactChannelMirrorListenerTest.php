<?php

/**
 * Unit tests for ContactChannelMirrorListener.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Listener\ContactChannelMirrorListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * The single email and phone fields follow the primary list entry on every save.
 */
class ContactChannelMirrorListenerTest extends TestCase {

	public function testApplicationRegistersTheListenerBeforeCreateAndUpdate(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			function (string $event, string $listener, int $priority = 0) use (&$registered): void {
				$registered[] = [$event, $listener];
			}
		);

		$app = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
		$app->register($context);

		$this->assertContains([ObjectCreatingEvent::class, ContactChannelMirrorListener::class], $registered);
		$this->assertContains([ObjectUpdatingEvent::class, ContactChannelMirrorListener::class], $registered);
	}//end testApplicationRegistersTheListenerBeforeCreateAndUpdate()

	public function testANewPrimaryEmailBecomesTheEmail(): void {
		$event = $this->updating(
			old: ['email' => 'old@example.org', 'emails' => [['value' => 'old@example.org', 'primary' => true]]],
			new: [
				'email' => 'old@example.org',
				'emails' => [
					['value' => 'old@example.org', 'primary' => false],
					['value' => 'new@example.org', 'primary' => true],
				],
			]
		);

		$this->listener()->handle($event);

		$this->assertSame(['email' => 'new@example.org'], $event->getModifiedData());
	}//end testANewPrimaryEmailBecomesTheEmail()

	public function testWithoutAPrimaryTheFirstEntryCounts(): void {
		$event = $this->updating(
			old: ['phones' => []],
			new: ['phones' => [['value' => '+31612345678'], ['value' => '+31201234567']]]
		);

		$this->listener()->handle($event);

		$this->assertSame(['phone' => '+31612345678'], $event->getModifiedData());
	}//end testWithoutAPrimaryTheFirstEntryCounts()

	public function testAnEmptiedListClearsTheField(): void {
		$event = $this->updating(
			old: ['email' => 'a@example.org', 'emails' => [['value' => 'a@example.org', 'primary' => true]]],
			new: ['email' => 'a@example.org', 'emails' => []]
		);

		$this->listener()->handle($event);

		$this->assertSame(['email' => ''], $event->getModifiedData());
	}//end testAnEmptiedListClearsTheField()

	public function testAWriterThatSetsOnlyTheEmailIsLeftAlone(): void {
		$list = [['value' => 'a@example.org', 'primary' => true]];
		$event = $this->updating(
			old: ['email' => 'a@example.org', 'emails' => $list],
			new: ['email' => 'b@example.org', 'emails' => $list]
		);

		$this->listener()->handle($event);

		$this->assertSame([], $event->getModifiedData());
	}//end testAWriterThatSetsOnlyTheEmailIsLeftAlone()

	public function testACreateWithAListFillsTheField(): void {
		$event = new ObjectCreatingEvent(
			$this->entity(['emails' => [['value' => 'info@acme.example', 'primary' => true]]], 'sch-client')
		);

		$this->listener()->handle($event);

		$this->assertSame(['email' => 'info@acme.example'], $event->getModifiedData());
	}//end testACreateWithAListFillsTheField()

	public function testACreateWithAnEmptyListKeepsTheGivenEmail(): void {
		$event = new ObjectCreatingEvent($this->entity(['email' => 'info@acme.example', 'emails' => []], 'sch-client'));

		$this->listener()->handle($event);

		$this->assertSame([], $event->getModifiedData());
	}//end testACreateWithAnEmptyListKeepsTheGivenEmail()

	public function testItMergesWithDataAnotherListenerModified(): void {
		$event = new ObjectCreatingEvent($this->entity(['phones' => [['value' => '+3110', 'primary' => true]]]));
		$event->setModifiedData(['name' => 'Jan']);

		$this->listener()->handle($event);

		$this->assertSame(['name' => 'Jan', 'phone' => '+3110'], $event->getModifiedData());
	}//end testItMergesWithDataAnotherListenerModified()

	public function testAnotherSchemaIsIgnored(): void {
		$event = new ObjectCreatingEvent($this->entity(['emails' => [['value' => 'x@example.org']]], 'sch-lead'));

		$this->listener()->handle($event);

		$this->assertSame([], $event->getModifiedData());
	}//end testAnotherSchemaIsIgnored()

	private function updating(array $old, array $new): ObjectUpdatingEvent {
		return new ObjectUpdatingEvent($this->entity($new), $this->entity($old));
	}//end updating()

	private function entity(array $data, string $schema = 'sch-contact'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('party-1');
		$entity->setSchema($schema);
		$entity->setObject($data);

		return $entity;
	}//end entity()

	private function listener(): ContactChannelMirrorListener {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				$values = ['client_schema' => 'sch-client', 'contact_schema' => 'sch-contact'];

				return ($values[$key] ?? $default);
			}
		);

		return new ContactChannelMirrorListener(appConfig: $appConfig);
	}//end listener()
}//end class
