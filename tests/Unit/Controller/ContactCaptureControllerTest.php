<?php

/**
 * Unit tests for ContactCaptureController.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#requirement-pasted-text-becomes-proposed-contact-fields-req-ccc-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\ContactCaptureController;
use OCA\Pipelinq\Service\ContactCaptureService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The parse route: a signed-in user, some text, nothing stored.
 *
 * @covers \OCA\Pipelinq\Controller\ContactCaptureController
 */
class ContactCaptureControllerTest extends TestCase {

	/**
	 * The proposal double.
	 *
	 * @var ContactCaptureService&MockObject
	 */
	private ContactCaptureService&MockObject $capture;

	/**
	 * Set up the double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->capture = $this->createMock(ContactCaptureService::class);
	}//end setUp()

	/**
	 * The controller for a request.
	 *
	 * @param mixed $text   The posted text.
	 * @param bool  $signed Whether a user is signed in.
	 *
	 * @return ContactCaptureController The controller.
	 */
	private function controller(mixed $text, bool $signed = true): ContactCaptureController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->with('text', '')->willReturn($text);
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($signed === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('rep');
		}

		$session->method('getUser')->willReturn($user);

		return new ContactCaptureController(request: $request, capture: $this->capture, userSession: $session);
	}//end controller()

	/**
	 * The proposal for the text is answered for the signed-in user.
	 *
	 * @return void
	 */
	public function testTheProposalIsAnswered(): void {
		$proposal = ['fields' => ['email' => 'a@b.nl'], 'emails' => ['a@b.nl'], 'phones' => [], 'unassigned' => [], 'labelledBy' => 'rules'];
		$this->capture->expects($this->once())->method('parse')->with('a@b.nl', 'rep')->willReturn($proposal);

		$response = $this->controller(text: 'a@b.nl')->parse();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($proposal, $response->getData());
	}//end testTheProposalIsAnswered()

	/**
	 * No text, blank text or a non-string is a 400 and nothing is parsed.
	 *
	 * @return void
	 */
	public function testNoTextIs400(): void {
		$this->capture->expects($this->never())->method('parse');

		foreach (['', '   ', ['a']] as $text) {
			$response = $this->controller(text: $text)->parse();
			$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
			$this->assertSame(['error' => 'Paste the text to read.'], $response->getData());
		}
	}//end testNoTextIs400()

	/**
	 * More than a signature's worth of text is a 413.
	 *
	 * @return void
	 */
	public function testTooMuchTextIs413(): void {
		$this->capture->expects($this->never())->method('parse');

		$response = $this->controller(text: str_repeat('é', ContactCaptureService::MAX_LENGTH + 1))->parse();

		$this->assertSame(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, $response->getStatus());
		$this->assertSame(Http::STATUS_OK, $this->controllerAccepting(text: str_repeat('é', ContactCaptureService::MAX_LENGTH))->parse()->getStatus());
	}//end testTooMuchTextIs413()

	/**
	 * Without a signed-in user nothing is parsed.
	 *
	 * @return void
	 */
	public function testWithoutAUserIs401(): void {
		$this->capture->expects($this->never())->method('parse');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(text: 'a@b.nl', signed: false)->parse()->getStatus());
	}//end testWithoutAUserIs401()

	/**
	 * A controller whose service answers an empty proposal.
	 *
	 * @param string $text The posted text.
	 *
	 * @return ContactCaptureController The controller.
	 */
	private function controllerAccepting(string $text): ContactCaptureController {
		$this->capture = $this->createMock(ContactCaptureService::class);
		$this->capture->method('parse')->willReturn(['fields' => [], 'emails' => [], 'phones' => [], 'unassigned' => [], 'labelledBy' => 'rules']);
		return $this->controller(text: $text);
	}//end controllerAccepting()
}//end class
