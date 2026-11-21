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
	 * A controller for a caller with the given query and privilege.
	 *
	 * @param array<string, string> $params     The query parameters.
	 * @param bool                  $privileged Whether the caller has the CRM privilege.
	 * @param bool                  $signedIn   Whether anyone is signed in.
	 *
	 * @return TemplateVariableController The controller.
	 */
	private function controller(array $params = [], bool $privileged = true, bool $signedIn = true): TemplateVariableController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('marketer');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $user : null);

		$policy = $this->createMock(ObjectOwnerAccessPolicy::class);
		$policy->method('isPrivileged')->willReturn($privileged);

		$names = $this->createMock(RecipientNameReader::class);
		$names->method('greetingName')->willReturnCallback(
			static fn (string $id): string => ($id === 'c-1' ? 'Anna' : '')
		);

		$this->compliance = $this->createMock(ComplianceServiceInterface::class);
		$this->blasts     = $this->createMock(BlastServiceInterface::class);

		return new TemplateVariableController(
			$request,
			$this->compliance,
			$this->blasts,
			new EmptyVariableCounter(recipientNames: $names),
			$session,
			$policy
		);
	}//end controller()

	/**
	 * The gaps for a segment's recipients, resolved for the template's channel.
	 *
	 * @return void
	 */
	public function testCountsTheGapsForASegment(): void {
		$controller = $this->controller(params: ['segmentId' => 'seg-1']);
		$this->compliance->method('getTemplateById')->with('welcome')->willReturn(
			['subject' => 'Dear {{preferredName}}', 'bodyText' => '{{city}}', 'channel' => 'email']
		);
		$this->blasts->expects(self::once())->method('audienceRecipients')
			->with('seg-1', '', 'email')
			->willReturn([['contactId' => 'c-1'], ['contactId' => 'c-2']]);

		$response = $controller->emptyVariables('welcome');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(
			[
				'recipients' => 2,
				'variables' => ['preferredName', 'city'],
				'gaps' => [['variable' => 'preferredName', 'count' => 1], ['variable' => 'city', 'count' => 2]],
			],
			$response->getData()
		);
	}//end testCountsTheGapsForASegment()

	/**
	 * A mailing list is passed on as a list.
	 *
	 * @return void
	 */
	public function testPassesAListOn(): void {
		$controller = $this->controller(params: ['listId' => ' list-1 ']);
		$this->compliance->method('getTemplateById')->willReturn(['subject' => 'Hi']);
		$this->blasts->expects(self::once())->method('audienceRecipients')->with('', 'list-1', 'email')->willReturn([]);

		self::assertSame(['recipients' => 0, 'variables' => [], 'gaps' => []], $controller->emptyVariables('t')->getData());
	}//end testPassesAListOn()

	/**
	 * An unknown template is a 404 and resolves no audience.
	 *
	 * @return void
	 */
	public function testAnUnknownTemplateIsNotFound(): void {
		$controller = $this->controller(params: ['segmentId' => 'seg-1']);
		$this->compliance->method('getTemplateById')->willReturn(null);
		$this->blasts->expects(self::never())->method('audienceRecipients');

		self::assertSame(Http::STATUS_NOT_FOUND, $controller->emptyVariables('missing')->getStatus());
	}//end testAnUnknownTemplateIsNotFound()

	/**
	 * A caller without the CRM privilege is refused; an anonymous one is asked to sign in.
	 *
	 * @return void
	 */
	public function testTheCallerIsGated(): void {
		$controller = $this->controller(privileged: false);
		$this->compliance->expects(self::never())->method('getTemplateById');
		self::assertSame(Http::STATUS_FORBIDDEN, $controller->emptyVariables('t')->getStatus());

		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(signedIn: false)->emptyVariables('t')->getStatus());
	}//end testTheCallerIsGated()
}//end class
