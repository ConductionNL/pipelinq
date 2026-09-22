<?php

/**
 * Contract tests for PartyLeafController.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Pipelinq\Controller\PartyLeafController;
use OCA\Pipelinq\Integration\PartyLeafProvider;
use OCA\Pipelinq\Service\PartyIndicatorService;
use OCA\Pipelinq\Service\PartyOrganisationTreeService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The wire contract of the party leaf: panel, blocked and acknowledge.
 */
class PartyLeafControllerTest extends TestCase {
	/**
	 * The party leaf provider double.
	 *
	 * @var PartyLeafProvider
	 */
	private PartyLeafProvider $provider;

	/**
	 * The indicator service double.
	 *
	 * @var PartyIndicatorService
	 */
	private PartyIndicatorService $indicators;

	/**
	 * Build the controller over doubles.
	 *
	 * @return PartyLeafController The controller under test.
	 */
	private function controller(): PartyLeafController {
		$this->provider = $this->getMockBuilder(PartyLeafProvider::class)
			->disableOriginalConstructor()
			->onlyMethods(['describe'])
			->getMock();

		$this->indicators = $this->getMockBuilder(PartyIndicatorService::class)
			->disableOriginalConstructor()
			->onlyMethods(['isBlocked', 'acknowledge'])
			->getMock();

		$tree = $this->getMockBuilder(PartyOrganisationTreeService::class)
			->disableOriginalConstructor()
			->onlyMethods(['setParent'])
			->getMock();

		return new PartyLeafController(
			$this->createMock(IRequest::class),
			$this->provider,
			$this->indicators,
			$tree,
		);
	}//end controller()

	/**
	 * The panel answers 200 with the leaf's body, and `status` is not echoed
	 * back into it.
	 *
	 * @return void
	 */
	public function testThePanelAnswersTheLeafBody(): void {
		$controller = $this->controller();
		$this->provider->method('describe')->willReturn(
			['status' => 200, 'party' => ['id' => 'party-1'], 'indicators' => []]
		);

		$response = $controller->panel(partyId: 'party-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['party' => ['id' => 'party-1'], 'indicators' => []], $response->getData());
	}//end testThePanelAnswersTheLeafBody()

	/**
	 * A refusal from the leaf keeps its status on the wire.
	 *
	 * @return void
	 */
	public function testThePanelCarriesTheLeafsRefusal(): void {
		$controller = $this->controller();
		$this->provider->method('describe')->willReturn(
			['status' => 403, 'error' => 'You may not read this party.']
		);

		$response = $controller->panel(partyId: 'party-1');

		$this->assertSame(403, $response->getStatus());
	}//end testThePanelCarriesTheLeafsRefusal()

	/**
	 * `blocked` refuses before it answers: a caller who may not read the party
	 * is not told whether an indicator blocks an act on it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/party-fields-and-indicators/spec.md#requirement-pipelinq-shall-answer-whether-an-indicator-blocks-an-act-and-shall-not-intercept-it-req-pfi-004
	 */
	public function testBlockedRefusesWithoutConsultingTheIndicators(): void {
		$controller = $this->controller();
		$this->provider->method('describe')->willReturn(['status' => 403, 'error' => 'You may not read this party.']);
		$this->indicators->expects($this->never())->method('isBlocked');

		$response = $controller->blocked(partyId: 'party-1', act: 'send');

		$this->assertSame(403, $response->getStatus());
	}//end testBlockedRefusesWithoutConsultingTheIndicators()

	/**
	 * A readable party gets the indicator answer, whole, at 200.
	 *
	 * @return void
	 */
	public function testBlockedAnswersWithTheIndicatorsThatBlock(): void {
		$controller = $this->controller();
		$this->provider->method('describe')->willReturn(['status' => 200, 'party' => []]);
		$this->indicators->method('isBlocked')->willReturn(
			['blocked' => true, 'act' => 'send', 'indicators' => [['code' => 'deceased']]]
		);

		$response = $controller->blocked(partyId: 'party-1', act: 'send');

		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($response->getData()['blocked']);
		$this->assertSame('deceased', $response->getData()['indicators'][0]['code']);
	}//end testBlockedAnswersWithTheIndicatorsThatBlock()

	/**
	 * An act the service does not answer for is a 400, not a 500.
	 *
	 * @return void
	 */
	public function testAnUnknownActIsABadRequest(): void {
		$controller = $this->controller();
		$this->provider->method('describe')->willReturn(['status' => 200, 'party' => []]);
		$this->indicators->method('isBlocked')->willThrowException(
			new InvalidArgumentException('act must be one of send, publishAddress, handle.')
		);

		$response = $controller->blocked(partyId: 'party-1', act: 'teleport');

		$this->assertSame(400, $response->getStatus());
		$this->assertStringContainsString('act must be one of', $response->getData()['error']);
	}//end testAnUnknownActIsABadRequest()

	/**
	 * Acknowledging carries the service's status onto the wire.
	 *
	 * @return void
	 */
	public function testAcknowledgeCarriesTheServiceStatus(): void {
		$controller = $this->controller();
		$this->indicators->method('acknowledge')->willReturn(
			['status' => 404, 'error' => 'That indicator value could not be read.']
		);

		$response = $controller->acknowledge(valueId: 'value-1');

		$this->assertSame(404, $response->getStatus());
		$this->assertArrayNotHasKey('status', $response->getData());
	}//end testAcknowledgeCarriesTheServiceStatus()
}//end class
