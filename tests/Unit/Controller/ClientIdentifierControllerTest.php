<?php

/**
 * Unit tests for ClientIdentifierController.
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

use OCA\Pipelinq\Controller\ClientIdentifierController;
use OCA\Pipelinq\Kernel\Contracts\ClientIdentifierServiceInterface;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The Check route hands the request to the service and answers its status.
 *
 * @spec openspec/changes/clients-registry-numbers-and-enrichment/specs/client-registry-numbers/spec.md
 */
#[CoversClass(ClientIdentifierController::class)]
class ClientIdentifierControllerTest extends TestCase {

	/**
	 * A controller over the given request parameters and service answer.
	 *
	 * @param array<string, string>                                    $params The request parameters.
	 * @param array{status: int, identifier?: array, message?: string} $answer What the service answers.
	 * @param array<int, array<string, string>>                        $calls  Filled with the service calls.
	 * @param bool                                                     $mayOpen Whether the caller may open the client.
	 *
	 * @return ClientIdentifierController The controller.
	 */
	private function controller(array $params, array $answer, array &$calls, bool $mayOpen = true): ClientIdentifierController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$service = $this->createMock(originalClassName: ClientIdentifierServiceInterface::class);
		$service->method('canAccessClient')->willReturn($mayOpen);
		$service->method('check')->willReturnCallback(
			static function (string $clientId, string $scheme, string $value) use ($answer, &$calls): array {
				$calls[] = ['clientId' => $clientId, 'scheme' => $scheme, 'value' => $value];
				return $answer;
			}
		);

		return new ClientIdentifierController(request: $request, identifiers: $service);
	}//end controller()

	/**
	 * A check answers the checked identifier with 200.
	 *
	 * @return void
	 */
	public function testACheckAnswersTheCheckedIdentifier(): void {
		$calls = [];
		$identifier = ['scheme' => 'kvk', 'value' => '12345678', 'checkStatus' => 'valid'];
		$response = $this->controller(
			params: ['scheme' => ' kvk ', 'value' => '12345678'],
			answer: ['status' => 200, 'identifier' => $identifier],
			calls: $calls
		)
			->check(id: 'c1');

		$this->assertSame(expected: 200, actual: $response->getStatus());
		$this->assertSame(expected: ['identifier' => $identifier], actual: $response->getData());
		$this->assertSame(expected: [['clientId' => 'c1', 'scheme' => 'kvk', 'value' => '12345678']], actual: $calls);
	}//end testACheckAnswersTheCheckedIdentifier()

	/**
	 * A refusal keeps the service's status and message.
	 *
	 * @return void
	 */
	public function testARefusalKeepsItsStatus(): void {
		$calls = [];
		$response = $this->controller(params: [], answer: ['status' => 403, 'message' => 'You may not open this client.'], calls: $calls)->check(id: 'c1');

		$this->assertSame(expected: 403, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'You may not open this client.'], actual: $response->getData());
		$this->assertSame(expected: [['clientId' => 'c1', 'scheme' => '', 'value' => '']], actual: $calls);
	}//end testARefusalKeepsItsStatus()

	/**
	 * A client the caller may not open answers 403 and is never checked.
	 *
	 * @return void
	 */
	public function testAClientTheCallerMayNotOpenIsNotChecked(): void {
		$calls = [];
		$response = $this->controller(params: ['scheme' => 'kvk', 'value' => '12345678'], answer: ['status' => 200], calls: $calls, mayOpen: false)
			->check(id: 'someone-elses');

		$this->assertSame(expected: 403, actual: $response->getStatus());
		$this->assertSame(expected: [], actual: $calls);
	}//end testAClientTheCallerMayNotOpenIsNotChecked()

	/**
	 * The route is open to non-admins: the object check is the service's.
	 *
	 * @return void
	 */
	public function testTheRouteIsOpenToNonAdmins(): void {
		$attributes = (new ReflectionMethod(ClientIdentifierController::class, 'check'))->getAttributes(NoAdminRequired::class);
		$this->assertCount(expectedCount: 1, haystack: $attributes);
	}//end testTheRouteIsOpenToNonAdmins()
}//end class
