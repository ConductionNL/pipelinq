<?php

/**
 * Unit tests for TemplateInUseGuard.
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
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-mail-templates/specs/marketing-mail-templates/spec.md#REQ-MMT-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\Pipelinq\Listener\TemplateInUseGuard;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A template a scheduled mailing uses cannot be deleted (REQ-MMT-001).
 *
 * @spec openspec/changes/marketing-mail-templates/specs/marketing-mail-templates/spec.md#REQ-MMT-001
 */
class TemplateInUseGuardTest extends TestCase {

	/**
	 * The object service mock.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * The filters each findAll was asked for.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $asked = [];

	/**
	 * A guard over the given mailings.
	 *
	 * @param array<int, mixed> $mailings The rows findAll returns.
	 *
	 * @return TemplateInUseGuard
	 */
	private function guard(array $mailings): TemplateInUseGuard {
		$this->asked = [];
		$asked = &$this->asked;
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->objects->method('findAll')->willReturnCallback(
			static function (array $config = []) use (&$asked, $mailings): array {
				$asked[] = $config['filters'];
				return $mailings;
			}
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => [
				'register' => 'r-1',
				'blast_schema' => 's-blast',
				'campaignTemplate_schema' => 's-template',
			][$key] ?? $default
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		return new TemplateInUseGuard($this->objects, $config, $l10n, $this->createMock(LoggerInterface::class));
	}//end guard()

	/**
	 * An object entity.
	 *
	 * @param string               $uuid   The uuid.
	 * @param string               $schema The schema id.
	 * @param array<string, mixed> $data   The body.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, string $schema, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * The delete event for the "Monthly newsletter" template.
	 *
	 * @return ObjectDeletingEvent
	 */
	private function deletingTemplate(): ObjectDeletingEvent {
		return new ObjectDeletingEvent($this->entity('t-1', 's-template', ['name' => 'Monthly newsletter']));
	}//end deletingTemplate()

	/**
	 * A scheduled mailing using the template refuses the delete, naming the mailing.
	 *
	 * @return void
	 */
	public function testAScheduledMailingBlocksTheDelete(): void {
		$guard = $this->guard(
			[
				$this->entity('b-1', 's-blast', ['name' => 'November newsletter', 'templateId' => 't-1', 'status' => 'scheduled']),
			]
		);
		$event = $this->deletingTemplate();

		$guard->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('This template is used by the scheduled mailing November newsletter', $event->getErrors()['message']);
		$this->assertSame(
			[['register' => 'r-1', 'schema' => 's-blast', 'templateId' => 't-1', 'status' => 'scheduled']],
			$this->asked
		);
	}//end testAScheduledMailingBlocksTheDelete()

	/**
	 * Array rows are read as well as entities.
	 *
	 * @return void
	 */
	public function testAnArrayRowIsReadToo(): void {
		$guard = $this->guard([['name' => 'Event invitation', 'templateId' => 't-1', 'status' => 'scheduled']]);
		$event = $this->deletingTemplate();

		$guard->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertStringContainsString('Event invitation', $event->getErrors()['message']);
	}//end testAnArrayRowIsReadToo()

	/**
	 * Mailings that are sent, draft or use another template do not block.
	 *
	 * @return void
	 */
	public function testOtherMailingsDoNotBlock(): void {
		$guard = $this->guard(
			[
				$this->entity('b-1', 's-blast', ['name' => 'October newsletter', 'templateId' => 't-1', 'status' => 'sent']),
				$this->entity('b-2', 's-blast', ['name' => 'Draft', 'templateId' => 't-1', 'status' => 'draft']),
				$this->entity('b-3', 's-blast', ['name' => 'Other', 'templateId' => 't-2', 'status' => 'scheduled']),
				'not-a-row',
			]
		);
		$event = $this->deletingTemplate();

		$guard->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getErrors());
	}//end testOtherMailingsDoNotBlock()

	/**
	 * An object of another schema is never looked at.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsIgnored(): void {
		$guard = $this->guard([['name' => 'X', 'templateId' => 'l-1', 'status' => 'scheduled']]);
		$this->objects->expects($this->never())->method('findAll');
		$event = new ObjectDeletingEvent($this->entity('l-1', 's-lead', ['title' => 'Lead']));

		$guard->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAnotherSchemaIsIgnored()

	/**
	 * Unreadable mailings refuse the delete rather than risk it.
	 *
	 * @return void
	 */
	public function testUnreadableMailingsRefuseTheDelete(): void {
		$guard = $this->guard([]);
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->objects->method('findAll')->willThrowException(new RuntimeException('store down'));
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => [
				'register' => 'r-1',
				'blast_schema' => 's-blast',
				'campaignTemplate_schema' => 's-template',
			][$key] ?? $default
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		$guard = new TemplateInUseGuard($this->objects, $config, $l10n, $this->createMock(LoggerInterface::class));
		$event = $this->deletingTemplate();

		$guard->handle($event);

		$this->assertTrue($event->isPropagationStopped());
	}//end testUnreadableMailingsRefuseTheDelete()

	/**
	 * Other events pass through untouched.
	 *
	 * @return void
	 */
	public function testOtherEventsAreIgnored(): void {
		$guard = $this->guard([['name' => 'X', 'templateId' => 't-1', 'status' => 'scheduled']]);
		$this->objects->expects($this->never())->method('findAll');

		$guard->handle(new ObjectDeletedEvent($this->entity('t-1', 's-template', [])));

		$this->assertSame([], $this->asked);
	}//end testOtherEventsAreIgnored()
}//end class
