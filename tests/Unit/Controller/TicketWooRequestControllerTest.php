<?php

/**
 * "Omzetten naar Woo-verzoek" reaches only privileged employees who may read the ticket.
 *
 * @category Tests
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.pipelinq.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-turns-a-question-into-a-woo-request-req-qcd-008
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\TicketWooRequestController;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\Portal\MainRegisterReader;
use OCA\Pipelinq\Service\WooRequestConversionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TicketWooRequestController.
 *
 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-turns-a-question-into-a-woo-request-req-qcd-008
 */
class TicketWooRequestControllerTest extends TestCase {

	/**
	 * A controller for one employee.
	 *
	 * @param bool                      $signedIn   Whether someone is signed in.
	 * @param bool                      $privileged Whether they are in a privileged group.
	 * @param array<string, mixed>|null $ticket     What the RBAC read returns.
	 * @param array<string, mixed>      $result     What the conversion answers.
	 *
	 * @return TicketWooRequestController
	 */
	private function controller(bool $signedIn, bool $privileged, ?array $ticket, array $result = ['status' => 'converted']): TicketWooRequestController {
		$session = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('kcc-anna');
		$session->method('getUser')->willReturn(($signedIn === true) ? $user : null);

		$policy = $this->createMock(ObjectOwnerAccessPolicy::class);
		$policy->method('isPrivileged')->willReturn($privileged);

		$tickets = $this->createMock(MainRegisterReader::class);
		$tickets->method('find')->willReturn($ticket);

		$conversion = $this->createMock(WooRequestConversionService::class);
		$conversion->method('convert')->willReturn($result);
		$conversion->method('availability')->willReturn(['available' => true, 'canConvert' => true, 'status' => 'new', 'caseReference' => '']);

		return new TicketWooRequestController(
			request: $this->createMock(IRequest::class),
			conversion: $conversion,
			tickets: $tickets,
			userSession: $session,
			accessPolicy: $policy
		);
	}//end controller()

	/**
	 * Unauthenticated, unprivileged and unreadable tickets are refused before any conversion.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-turns-a-question-into-a-woo-request-req-qcd-008
	 */
	public function testOnlyAPrivilegedEmployeeWhoMayReadTheTicketConverts(): void {
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(signedIn: false, privileged: true, ticket: ['id' => 't-9'])->convert(id: 't-9')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller(signedIn: true, privileged: false, ticket: ['id' => 't-9'])->convert(id: 't-9')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller(signedIn: true, privileged: true, ticket: null)->convert(id: 't-9')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller(signedIn: true, privileged: false, ticket: ['id' => 't-9'])->availability(id: 't-9')->getStatus());
	}//end testOnlyAPrivilegedEmployeeWhoMayReadTheTicketConverts()

	/**
	 * Scenario: dossiq is not installed, a direct call answers 409.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-turns-a-question-into-a-woo-request-req-qcd-008
	 */
	public function testTheConversionAnswerMapsToHttp(): void {
		$cases = [
			['status' => 'converted', 'caseReference' => 'c-1', 'caseUrl' => ''],
			['status' => 'not-available'],
			['status' => 'not-convertible'],
			['status' => 'intake-failed'],
			['status' => 'converted-unsynced', 'caseReference' => 'c-1', 'caseUrl' => ''],
		];
		$expected = [Http::STATUS_OK, Http::STATUS_CONFLICT, Http::STATUS_CONFLICT, Http::STATUS_BAD_GATEWAY, Http::STATUS_OK];

		foreach ($cases as $i => $result) {
			$response = $this->controller(signedIn: true, privileged: true, ticket: ['id' => 't-9'], result: $result)->convert(id: 't-9');
			$this->assertSame($expected[$i], $response->getStatus(), $result['status']);
			$this->assertSame($result, $response->getData());
		}

		$this->assertTrue($this->controller(signedIn: true, privileged: true, ticket: ['id' => 't-9'])->availability(id: 't-9')->getData()['canConvert']);
	}//end testTheConversionAnswerMapsToHttp()
}//end class
