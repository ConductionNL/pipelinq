<?php

/**
 * Unit tests for WebFormController.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/website-enquiry-intake/spec.md#requirement-a-website-form-submits-into-the-destination-it-names
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\WebFormController;
use OCA\Pipelinq\Service\WebForm\WebFormIntakeService;
use OCA\Pipelinq\Service\WebForm\WebFormSubmitException;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The endpoint answers 201 with the destination's reference, or the intake's refusal.
 */
class WebFormControllerTest extends TestCase {

	/**
	 * The intake of the last built controller.
	 *
	 * @var WebFormIntakeService&MockObject
	 */
	private WebFormIntakeService $intake;

	/**
	 * A controller over a request with the given params and headers.
	 *
	 * @param array<string, mixed>  $params  The request params.
	 * @param array<string, string> $headers The request headers.
	 *
	 * @return WebFormController The controller.
	 */
	private function controller(array $params, array $headers = []): WebFormController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => ($headers[$name] ?? ''));
		$this->intake = $this->createMock(WebFormIntakeService::class);

		return new WebFormController($request, $this->intake, $this->createMock(LoggerInterface::class));
	}//end controller()

	/**
	 * Both actions are public, CSRF-free and rate limited.
	 *
	 * @return void
	 */
	public function testActionsArePublicAndRateLimited(): void {
		foreach (['submit', 'submitBySource'] as $method) {
			$reflection = new ReflectionMethod(WebFormController::class, $method);
			$this->assertNotEmpty($reflection->getAttributes(PublicPage::class), $method);
			$this->assertNotEmpty($reflection->getAttributes(NoCSRFRequired::class), $method);
			$this->assertNotEmpty($reflection->getAttributes(AnonRateLimit::class), $method);
		}
	}//end testActionsArePublicAndRateLimited()

	/**
	 * The old address reads the form's key from `source`, and answers 201 with id, reference and receivedAt.
	 *
	 * @return void
	 */
	public function testSubmitBySourceCreatesAndAnswers201(): void {
		$controller = $this->controller(['source' => 'website-support', 'message' => 'Hi'], ['Origin' => 'https://conduction.nl', 'Idempotency-Key' => 'k-1']);
		$this->intake->expects($this->once())->method('submitByKey')
			->with('website-support', ['source' => 'website-support', 'message' => 'Hi'], 'k-1')
			->willReturn(['id' => 't-1', 'reference' => 'TICKET-1', 'receivedAt' => '2026-10-11T09:00:00+00:00', 'confirmation' => []]);

		$response = $controller->submitBySource();

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(['id' => 't-1', 'reference' => 'TICKET-1', 'receivedAt' => '2026-10-11T09:00:00+00:00'], $response->getData());
		// Read by reflection: Response::getHeaders() reaches the server container for its CSP default.
		$headers = new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers');
		$this->assertSame('https://conduction.nl', $headers->getValue($response)['Access-Control-Allow-Origin']);
	}//end testSubmitBySourceCreatesAndAnswers201()

	/**
	 * The keyed address takes the key from the route and does not pass it on as an answer.
	 *
	 * @return void
	 */
	public function testSubmitTakesTheKeyFromTheRoute(): void {
		$controller = $this->controller(['key' => 'website-partner', 'message' => 'Hi']);
		$this->intake->expects($this->once())->method('submitByKey')
			->with('website-partner', ['message' => 'Hi'], null)
			->willReturn(['id' => 'l-1', 'reference' => 'LEAD-1', 'receivedAt' => 'now', 'confirmation' => []]);

		$this->assertSame(201, $controller->submit(key: 'website-partner')->getStatus());
	}//end testSubmitTakesTheKeyFromTheRoute()

	/**
	 * A refusal keeps the intake's status; a 422 carries its findings, a 400 none.
	 *
	 * @return void
	 */
	public function testRefusalsKeepTheirStatusAndFindings(): void {
		$controller = $this->controller(['source' => 'website-support']);
		$findings = [['property' => 'reporterEmail', 'code' => 'format']];
		$this->intake->method('submitByKey')->willReturnOnConsecutiveCalls(
			$this->throwException(new WebFormSubmitException('Check the answers.', 422, $findings)),
			$this->throwException(new WebFormSubmitException(WebFormIntakeService::REFUSED, 400))
		);

		$refused = $controller->submitBySource();
		$this->assertSame(422, $refused->getStatus());
		$this->assertSame(['error' => 'Check the answers.', 'findings' => $findings], $refused->getData());

		$bad = $controller->submitBySource();
		$this->assertSame(400, $bad->getStatus());
		$this->assertSame(['error' => WebFormIntakeService::REFUSED], $bad->getData());
	}//end testRefusalsKeepTheirStatusAndFindings()

	/**
	 * Anything unexpected answers 503 "try again later", never a stack trace.
	 *
	 * @return void
	 */
	public function testUnexpectedFailureAnswers503(): void {
		$controller = $this->controller(['source' => ['not', 'a', 'string']]);
		$this->intake->method('submitByKey')->with('')->willThrowException(new RuntimeException('boom'));

		$response = $controller->submitBySource();

		$this->assertSame(503, $response->getStatus());
		$this->assertSame(['error' => WebFormIntakeService::TRY_LATER], $response->getData());
	}//end testUnexpectedFailureAnswers503()
}//end class
