<?php

/**
 * BerichtenboxZaakStatusListener over update events that do not carry the
 * full OpenRegister shape.
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

use OCA\Pipelinq\Listener\BerichtenboxZaakStatusListener;
use OCA\Pipelinq\Service\Berichtenbox\CaseStatusMessage;
use OCA\Pipelinq\Service\BerichtenboxService;
use OCA\Pipelinq\Service\TicketService;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * An "ObjectUpdatedEvent" without getNewObject is not a case update; an
 * updated object whose data is not an array queues nothing; an event without
 * getOldObject treats the previous status as empty and still queues the change.
 *
 * @covers \OCA\Pipelinq\Listener\BerichtenboxZaakStatusListener
 * @uses   \OCA\Pipelinq\Service\Berichtenbox\CaseStatusMessage
 * @uses   \OCA\Pipelinq\Service\Berichtenbox\MessageOptions
 */
class BerichtenboxZaakStatusListenerDuckTypingTest extends TestCase {

	/**
	 * The bridge service.
	 *
	 * @var BerichtenboxService&MockObject
	 */
	private BerichtenboxService&MockObject $berichtenbox;

	/**
	 * The app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $config;

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
		$this->config = $this->createMock(originalClassName: IAppConfig::class);
		$this->config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'zaak_schema' ? 'sch-case' : $default)
		);
		$this->berichtenbox = $this->createMock(originalClassName: BerichtenboxService::class);

		$this->listener = new BerichtenboxZaakStatusListener(
			appConfig: $this->config,
			berichtenbox: $this->berichtenbox,
			ticketService: $this->createMock(originalClassName: TicketService::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * An updated-object event class without getNewObject is ignored before
	 * any config is read.
	 *
	 * @return void
	 */
	public function testAnUpdateEventWithoutANewObjectIsIgnored(): void {
		$this->config->expects($this->never())->method('getValueString');
		$this->berichtenbox->expects($this->never())->method('queueOutboundMessage');

		$this->listener->handle(event: new BareObjectUpdatedEvent());
	}//end testAnUpdateEventWithoutANewObjectIsIgnored()

	/**
	 * A case whose object data is not an array queues nothing.
	 *
	 * @return void
	 */
	public function testACaseWithoutArrayDataQueuesNothing(): void {
		$this->berichtenbox->expects($this->never())->method('queueOutboundMessage');

		$this->listener->handle(event: new NewOnlyObjectUpdatedEvent(new DuckCase(data: 'handled')));
	}//end testACaseWithoutArrayDataQueuesNothing()

	/**
	 * Without getOldObject the previous status counts as empty, so a case
	 * now in a trigger status is queued with its id, status and BSN.
	 *
	 * @return void
	 */
	public function testAnEventWithoutAnOldObjectStillQueuesTheChange(): void {
		$this->berichtenbox->expects($this->once())->method('queueOutboundMessage')->with(
			$this->callback(
				static fn (CaseStatusMessage $message): bool => $message->caseId === 'case-7'
					&& $message->status === 'handled'
					&& $message->bsn === '999993653'
			),
			$this->anything()
		);

		$this->listener->handle(
			event: new NewOnlyObjectUpdatedEvent(new DuckCase(data: ['id' => 'case-7', 'status' => 'handled', 'bsn' => '999993653']))
		);
	}//end testAnEventWithoutAnOldObjectStillQueuesTheChange()
}//end class

/**
 * An event whose class name says "ObjectUpdatedEvent" but carries no object.
 */
class BareObjectUpdatedEvent extends Event {
}//end class

/**
 * An updated-object event that only exposes the object after the change.
 */
class NewOnlyObjectUpdatedEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param DuckCase $newObject The object after the change.
	 */
	public function __construct(private DuckCase $newObject) {
		parent::__construct();
	}//end __construct()

	/**
	 * The object after the change.
	 *
	 * @return DuckCase The object.
	 */
	public function getNewObject(): DuckCase {
		return $this->newObject;
	}//end getNewObject()
}//end class

/**
 * A case object on the configured case schema with arbitrary data.
 */
class DuckCase {

	/**
	 * Constructor.
	 *
	 * @param mixed $data The object data, array or not.
	 */
	public function __construct(private mixed $data) {
	}//end __construct()

	/**
	 * The schema id.
	 *
	 * @return string The schema.
	 */
	public function getSchema(): string {
		return 'sch-case';
	}//end getSchema()

	/**
	 * The object data.
	 *
	 * @return mixed The data.
	 */
	public function getObject(): mixed {
		return $this->data;
	}//end getObject()
}//end class
