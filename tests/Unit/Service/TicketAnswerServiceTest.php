<?php

/**
 * Unit tests for TicketAnswerService and TicketAnswerController.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTime;
use DomainException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Controller\TicketAnswerController;
use OCA\Pipelinq\Service\TicketAnswerService;
use OCA\Pipelinq\Service\TicketService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The answer to the customer is saved by the server: customerMessage, the
 * answer added to portalAnswers once, and the waiting status when asked.
 */
class TicketAnswerServiceTest extends TestCase {

	private array $stored = [];

	private array $patches = [];

	private bool $refuse = false;

	public function testAnAnswerIsTheMessageAndIsKeptWithItsMoment(): void {
		$this->stored = ['ticketType' => 'request', 'status' => 'in_progress', 'portalAnswers' => [['message' => 'Old', 'createdAt' => '2026-10-01T09:00:00+00:00']]];
		$saved = $this->service()->answer(id: 't-1', message: '  Here is the answer.  ', waitForCustomer: true);

		$this->assertSame('Here is the answer.', $this->patches[0]['customerMessage']);
		$this->assertSame(['message' => 'Here is the answer.', 'createdAt' => '2026-10-21T10:00:00+00:00'], $this->patches[0]['portalAnswers'][1]);
		$this->assertSame('awaiting_customer', $this->patches[0]['status']);
		$this->assertSame('t-1', $saved['id']);
	}

	public function testWithoutWaitingTheStatusStays(): void {
		$this->stored = ['ticketType' => 'complaint', 'status' => 'in_progress'];
		$this->service()->answer(id: 't-1', message: 'Answer', waitForCustomer: false);

		$this->assertArrayNotHasKey('status', $this->patches[0]);
	}

	public function testTheSameAnswerTwiceIsKeptOnce(): void {
		$answers = TicketAnswerService::answersAfter(answers: [['message' => 'Same', 'createdAt' => 'x']], message: 'Same', moment: 'y');

		$this->assertSame([['message' => 'Same', 'createdAt' => 'x']], $answers);
	}

	public function testAContactMomentHasNobodyToAnswer(): void {
		$this->stored = ['ticketType' => 'interaction'];
		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('not-answerable');
		$this->service()->answer(id: 't-1', message: 'Answer', waitForCustomer: false);
	}

	public function testAnEmptyAnswerIsRefusedBeforeAnyRead(): void {
		$this->expectExceptionMessage('empty');
		$this->service()->answer(id: 't-1', message: '   ', waitForCustomer: false);
	}

	public function testTheControllerNamesARefusalWithItsStatus(): void {
		$this->stored = ['ticketType' => 'request'];
		$this->refuse = true;
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));
		$controller = new TicketAnswerController($this->createMock(IRequest::class), $this->service(), $session);

		$response = $controller->answer(id: 't-1', message: 'Answer');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['status' => 'refused'], $response->getData());
	}

	public function testTheControllerAnswersTheMessageItSaved(): void {
		$this->stored = ['ticketType' => 'request'];
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));
		$controller = new TicketAnswerController($this->createMock(IRequest::class), $this->service(), $session);

		$response = $controller->answer(id: 't-1', message: ' Hi ');

		$this->assertSame('Hi', $response->getData()['message']);
	}

	private function service(): TicketAnswerService {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(function (): ObjectEntity {
			$entity = new ObjectEntity();
			$entity->setObject($this->stored);
			return $entity;
		});
		$objectService->method('patchObject')->willReturnCallback(function (string $objectId, array $data): ObjectEntity {
			if ($this->refuse === true) {
				throw new \RuntimeException('no');
			}
			$this->patches[] = $data;
			$entity = new ObjectEntity();
			$entity->setObject(array_merge($this->stored, $data));
			return $entity;
		});

		$tickets = $this->createMock(TicketService::class);
		$tickets->method('isConfigured')->willReturn(true);
		$tickets->method('getObjectService')->willReturn($objectService);
		$tickets->method('getRegisterId')->willReturn('reg');
		$tickets->method('getSchemaId')->willReturn('sch-ticket');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-10-21T10:00:00+00:00'));

		return new TicketAnswerService($tickets, $time);
	}
}
