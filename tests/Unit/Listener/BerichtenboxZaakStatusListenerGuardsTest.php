<?php

/**
 * When BerichtenboxZaakStatusListener does and does not queue a message.
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
 * @spec openspec/specs/berichtenbox/spec.md#REQ-OUTBOUND-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Pipelinq\Listener\BerichtenboxZaakStatusListener;
use OCA\Pipelinq\Service\BerichtenboxService;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Only a status change into a trigger status, on the configured case schema,
 * with a resolvable BSN, reaches the queue; the BSN falls back to the linked
 * contact moment.
 *
 * @covers \OCA\Pipelinq\Listener\BerichtenboxZaakStatusListener
 * @uses   \OCA\Pipelinq\Service\Berichtenbox\CaseStatusMessage
 * @uses   \OCA\Pipelinq\Service\Berichtenbox\MessageOptions
 * @uses   \OCA\Pipelinq\AppInfo\OpenRegisterAutoloader
 */
class BerichtenboxZaakStatusListenerGuardsTest extends TestCase {
	/**
	 * The bridge service.
	 *
	 * @var BerichtenboxService&MockObject
	 */
	private BerichtenboxService&MockObject $berichtenbox;

	/**
	 * The ticket resolver.
	 *
	 * @var TicketService&MockObject
	 */
	private TicketService&MockObject $tickets;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The listener under test.
	 *
	 * @var BerichtenboxZaakStatusListener
	 */
	private BerichtenboxZaakStatusListener $listener;

	/**
	 * Build the listener with the case schema configured as `sch-case`.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'zaak_schema' ? 'sch-case' : $default)
		);
		$this->berichtenbox = $this->createMock(originalClassName: BerichtenboxService::class);
		$this->tickets = $this->createMock(originalClassName: TicketService::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);

		$this->listener = new BerichtenboxZaakStatusListener(
			appConfig: $config,
			berichtenbox: $this->berichtenbox,
			ticketService: $this->tickets,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * An object entity on a schema.
	 *
	 * @param array<string, mixed> $data   The object data.
	 * @param string               $schema The schema id.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(array $data, string $schema = 'sch-case'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * Fire an update from `received` to the given new data.
	 *
	 * @param array<string, mixed> $new    The case after the change.
	 * @param string               $schema The schema of the updated object.
	 *
	 * @return void
	 */
	private function update(array $new, string $schema = 'sch-case'): void {
		$this->listener->handle(
			event: new ObjectUpdatedEvent(
				newObject: $this->entity(data: $new, schema: $schema),
				oldObject: $this->entity(data: ['status' => 'received'])
			)
		);
	}//end update()

	/**
	 * A created event is not an update and is ignored.
	 *
	 * @return void
	 */
	public function testANonUpdateEventIsIgnored(): void {
		$this->berichtenbox->expects($this->never())->method('queueOutboundMessage');

		$this->listener->handle(event: new ObjectCreatedEvent(object: $this->entity(data: ['status' => 'handled', 'bsn' => '1'])));
	}//end testANonUpdateEventIsIgnored()

	/**
	 * An update on another schema is ignored.
	 *
	 * @return void
	 */
	public function testAnUpdateOnAnotherSchemaIsIgnored(): void {
		$this->berichtenbox->expects($this->never())->method('queueOutboundMessage');

		$this->update(new: ['status' => 'handled', 'bsn' => '123456782'], schema: 'sch-other');
	}//end testAnUpdateOnAnotherSchemaIsIgnored()

	/**
	 * An update on an object without a schema is ignored.
	 *
	 * @return void
	 */
	public function testAnUpdateWithoutASchemaIsIgnored(): void {
		$this->berichtenbox->expects($this->never())->method('queueOutboundMessage');

		$entity = new ObjectEntity();
		$entity->setObject(['status' => 'handled', 'bsn' => '123456782']);
		$this->listener->handle(event: new ObjectUpdatedEvent(newObject: $entity, oldObject: null));
	}//end testAnUpdateWithoutASchemaIsIgnored()

	/**
	 * An unchanged status is not dispatched again.
	 *
	 * @return void
	 */
	public function testAnUnchangedStatusIsIgnored(): void {
		$this->berichtenbox->expects($this->never())->method('queueOutboundMessage');

		$this->update(new: ['status' => 'received', 'bsn' => '123456782']);
	}//end testAnUnchangedStatusIsIgnored()

	/**
	 * An empty status is ignored.
	 *
	 * @return void
	 */
	public function testAnEmptyStatusIsIgnored(): void {
		$this->berichtenbox->expects($this->never())->method('queueOutboundMessage');

		$this->update(new: ['bsn' => '123456782']);
	}//end testAnEmptyStatusIsIgnored()

	/**
	 * A status outside the trigger list is ignored.
	 *
	 * @return void
	 */
	public function testANonTriggerStatusIsIgnored(): void {
		$this->berichtenbox->expects($this->never())->method('queueOutboundMessage');

		$this->update(new: ['status' => 'archived', 'bsn' => '123456782']);
	}//end testANonTriggerStatusIsIgnored()

	/**
	 * Without a BSN or a contact moment the case is skipped with an info log
	 * naming the case.
	 *
	 * @return void
	 */
	public function testACaseWithoutABsnIsSkippedAndLogged(): void {
		$this->berichtenbox->expects($this->never())->method('queueOutboundMessage');
		$this->tickets->expects($this->never())->method('isConfigured');
		$this->logger->expects($this->once())->method('info')->with(
			'BerichtenboxZaakStatusListener: no BSN resolved, skipping.',
			['caseId' => 'Z-1']
		);

		$this->update(new: ['id' => 'Z-1', 'status' => 'handled']);
	}//end testACaseWithoutABsnIsSkippedAndLogged()

	/**
	 * The BSN is read from the linked contact moment in the ticket schema.
	 *
	 * @return void
	 */
	public function testTheBsnIsResolvedViaTheContactMoment(): void {
		$row = $this->entity(data: ['bsn' => '999993653']);
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->expects($this->once())->method('find')
			->with('cm-1', [], false, 'reg-1', 'sch-ticket')
			->willReturn($row);
		$this->tickets->method('isConfigured')->willReturn(true);
		$this->tickets->method('getObjectService')->willReturn($objects);
		$this->tickets->method('getRegisterId')->willReturn('reg-1');
		$this->tickets->method('getSchemaId')->willReturn('sch-ticket');

		$queued = null;
		$this->berichtenbox->expects($this->once())->method('queueOutboundMessage')->willReturnCallback(
			function ($message) use (&$queued): array {
				$queued = $message;
				return [];
			}
		);

		$this->update(new: ['id' => 'Z-1', 'status' => 'handled', 'interactionId' => 'cm-1']);

		$this->assertSame(expected: '999993653', actual: $queued->bsn);
		$this->assertSame(expected: 'cm-1', actual: $queued->interactionId);
	}//end testTheBsnIsResolvedViaTheContactMoment()

	/**
	 * An unconfigured ticket schema yields no BSN, so nothing is queued.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredTicketSchemaYieldsNoBsn(): void {
		$this->tickets->method('isConfigured')->willReturn(false);
		$this->tickets->expects($this->never())->method('getObjectService');
		$this->berichtenbox->expects($this->never())->method('queueOutboundMessage');

		$this->update(new: ['id' => 'Z-1', 'status' => 'handled', 'interactionId' => 'cm-1']);
	}//end testAnUnconfiguredTicketSchemaYieldsNoBsn()

	/**
	 * A missing contact moment yields no BSN, so nothing is queued.
	 *
	 * @return void
	 */
	public function testAMissingContactMomentYieldsNoBsn(): void {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('find')->willReturn(null);
		$this->tickets->method('isConfigured')->willReturn(true);
		$this->tickets->method('getObjectService')->willReturn($objects);
		$this->berichtenbox->expects($this->never())->method('queueOutboundMessage');
		$this->logger->expects($this->once())->method('info')->with('BerichtenboxZaakStatusListener: no BSN resolved, skipping.');

		$this->update(new: ['id' => 'Z-1', 'status' => 'handled', 'interactionId' => 'cm-1']);
	}//end testAMissingContactMomentYieldsNoBsn()

	/**
	 * A failing contact-moment lookup is logged and yields no BSN.
	 *
	 * @return void
	 */
	public function testAFailingContactMomentLookupIsLogged(): void {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('find')->willThrowException(new \RuntimeException('db down'));
		$this->tickets->method('isConfigured')->willReturn(true);
		$this->tickets->method('getObjectService')->willReturn($objects);
		$this->berichtenbox->expects($this->never())->method('queueOutboundMessage');

		$messages = [];
		$this->logger->method('info')->willReturnCallback(
			function (string $message, array $context) use (&$messages): void {
				$messages[] = [$message, $context];
			}
		);

		$this->update(new: ['id' => 'Z-1', 'status' => 'handled', 'interactionId' => 'cm-1']);

		$this->assertSame(
			expected: [
				['BerichtenboxZaakStatusListener: BSN-via-contactmoment lookup failed.', ['exception' => 'db down']],
				['BerichtenboxZaakStatusListener: no BSN resolved, skipping.', ['caseId' => 'Z-1']],
			],
			actual: $messages
		);
	}//end testAFailingContactMomentLookupIsLogged()
}//end class
