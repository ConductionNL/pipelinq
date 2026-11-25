<?php

/**
 * GET /api/templates/{id}/empty-variables.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-mail-templates/specs/marketing-mail-templates/spec.md#REQ-MMT-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\CrmCallerGate;
use OCA\Pipelinq\Controller\TemplateVariableController;
use OCA\Pipelinq\Kernel\Contracts\BlastServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ComplianceServiceInterface;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\Marketing\EmptyVariableCounter;
use OCA\Pipelinq\Service\Marketing\RecipientNameReader;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * TemplateVariableController.
 */
class TemplateVariableControllerTest extends TestCase {

	/**
	 * The template reader.
	 *
	 * @var ComplianceServiceInterface&MockObject
	 */
	private ComplianceServiceInterface&MockObject $compliance;

	/**
	 * The audience resolver.
	 *
	 * @var BlastServiceInterface&MockObject
	 */
	private BlastServiceInterface&MockObject $blasts;

	/**
	 * A signed-in user session, or an anonymous one.
	 *
	 * @param bool $signedIn Whether anyone is signed in.
	 *
	 * @return IUserSession The session.
	 */
	private function session(bool $signedIn): IUserSession {
		$session = $this->createMock(originalClassName: IUserSession::class);
		if ($signedIn === false) {
			$session->method('getUser')->willReturn(null);
			return $session;
		}

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('marketer');
		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	/**
	 * A controller for a caller with the given query and privilege.
	 *
	 * @param array<string, string> $params     The query parameters.
	 * @param bool                  $privileged Whether the caller has the CRM privilege.
	 * @param bool                  $signedIn   Whether anyone is signed in.
	 *
	 * @return TemplateVariableController The controller.
	 */
	private function controller(array $params = [], bool $privileged = true, bool $signedIn = true): TemplateVariableController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		$policy = $this->createMock(originalClassName: ObjectOwnerAccessPolicy::class);
		$policy->method('isPrivileged')->willReturn($privileged);

		$names = $this->createMock(originalClassName: RecipientNameReader::class);
		$names->method('greetingName')->willReturn('');

		$this->compliance = $this->createMock(originalClassName: ComplianceServiceInterface::class);
		$this->blasts     = $this->createMock(originalClassName: BlastServiceInterface::class);
		$counter = new EmptyVariableCounter(templates: $this->compliance, audiences: $this->blasts, recipientNames: $names);

		return new TemplateVariableController(
			request: $request,
			counter: $counter,
			gate: new CrmCallerGate(userSession: $this->session(signedIn: $signedIn), policy: $policy)
		);
	}//end controller()

	/**
	 * The report for a segment comes back as JSON.
	 *
	 * @return void
	 */
	public function testAnswersTheReportForASegment(): void {
		$controller = $this->controller(params: ['segmentId' => ' seg-1 ']);
		$this->compliance->method('getTemplateById')->with('welcome')->willReturn(['bodyText' => '{{city}}']);
		$this->blasts->expects(self::once())->method('audienceRecipients')
			->with('seg-1', '', 'email')
			->willReturn([['contactId' => 'c-1'], ['contactId' => 'c-2']]);

		$response = $controller->emptyVariables(id: 'welcome');

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertSame(
			expected: ['recipients' => 2, 'variables' => ['city'], 'gaps' => [['variable' => 'city', 'count' => 2]]],
			actual: $response->getData()
		);
	}//end testAnswersTheReportForASegment()

	/**
	 * A mailing list is passed on as a list.
	 *
	 * @return void
	 */
	public function testPassesAListOn(): void {
		$controller = $this->controller(params: ['listId' => 'list-1']);
		$this->compliance->method('getTemplateById')->willReturn(['subject' => 'Hi']);
		$this->blasts->expects(self::once())->method('audienceRecipients')->with('', 'list-1', 'email')->willReturn([]);

		self::assertSame(
			expected: ['recipients' => 0, 'variables' => [], 'gaps' => []],
			actual: $controller->emptyVariables(id: 't')->getData()
		);
	}//end testPassesAListOn()

	/**
	 * An unknown template is a 404.
	 *
	 * @return void
	 */
	public function testAnUnknownTemplateIsNotFound(): void {
		$controller = $this->controller(params: ['segmentId' => 'seg-1']);
		$this->compliance->method('getTemplateById')->willReturn(null);

		self::assertSame(expected: Http::STATUS_NOT_FOUND, actual: $controller->emptyVariables(id: 'missing')->getStatus());
	}//end testAnUnknownTemplateIsNotFound()

	/**
	 * A caller without the CRM privilege is refused; an anonymous one is asked to sign in.
	 *
	 * @return void
	 */
	public function testTheCallerIsGated(): void {
		$controller = $this->controller(privileged: false);
		$this->compliance->expects(self::never())->method('getTemplateById');
		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $controller->emptyVariables(id: 't')->getStatus());

		self::assertSame(
			expected: Http::STATUS_UNAUTHORIZED,
			actual: $this->controller(signedIn: false)->emptyVariables(id: 't')->getStatus()
		);
	}//end testTheCallerIsGated()
}//end class
