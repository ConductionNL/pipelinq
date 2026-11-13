<?php

/**
 * Unit tests for EventReminderController.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\EventReminderController;
use OCA\Pipelinq\Kernel\Contracts\EventReminderServiceInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * EventReminderController sends a marketing event's reminder.
 */
class EventReminderControllerTest extends TestCase {

	/**
	 * A controller over a reminder service double, signed in or not.
	 *
	 * @param EventReminderServiceInterface $reminders The reminder service.
	 * @param bool                          $signedIn  Whether a user is signed in.
	 *
	 * @return EventReminderController
	 */
	private function controller(EventReminderServiceInterface $reminders, bool $signedIn=true): EventReminderController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $this->createMock(IUser::class) : null);

		return new EventReminderController(request: $this->createMock(IRequest::class), reminders: $reminders, userSession: $session);
	}//end controller()

	/**
	 * A sent reminder answers the service's result.
	 *
	 * @return void
	 */
	public function testAReminderIsSent(): void {
		$reminders = $this->createMock(EventReminderServiceInterface::class);
		$reminders->expects(self::once())->method('send')->with('ev-1')->willReturn(['sent' => 12]);

		self::assertSame(['sent' => 12], $this->controller($reminders)->send(id: 'ev-1')->getData());
	}//end testAReminderIsSent()

	/**
	 * An unknown event is a 404; any other refusal a 403; anonymous callers a 401.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$reminders = $this->createMock(EventReminderServiceInterface::class);
		$reminders->method('send')->willReturnCallback(static function (string $eventId): array {
			throw new RuntimeException($eventId === 'missing' ? 'event_not_found: missing' : 'not_owner');
		});

		$missing = $this->controller($reminders)->send(id: 'missing');
		self::assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());
		self::assertSame(['message' => 'Event not found'], $missing->getData());

		$foreign = $this->controller($reminders)->send(id: 'ev-2');
		self::assertSame(Http::STATUS_FORBIDDEN, $foreign->getStatus());

		$never = $this->createMock(EventReminderServiceInterface::class);
		$never->expects(self::never())->method('send');
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller($never, signedIn: false)->send(id: 'ev-1')->getStatus());
	}//end testRefusals()
}//end class
