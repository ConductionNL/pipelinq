<?php

/**
 * Contract tests for PartyKindController.
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

use OCA\Pipelinq\Controller\PartyKindController;
use OCA\Pipelinq\Service\PartyKindRegistryService;
use OCA\Pipelinq\Service\PartyLinkService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The wire contract of the party-kind registry surface.
 */
class PartyKindControllerTest extends TestCase {
	/**
	 * The link service double.
	 *
	 * @var PartyLinkService
	 */
	private PartyLinkService $links;

	/**
	 * The registry double.
	 *
	 * @var PartyKindRegistryService
	 */
	private PartyKindRegistryService $registry;

	/**
	 * Build the controller over doubles.
	 *
	 * @return PartyKindController The controller under test.
	 */
	private function controller(): PartyKindController {
		$this->registry = $this->getMockBuilder(PartyKindRegistryService::class)
			->disableOriginalConstructor()
			->onlyMethods(['acceptanceFor', 'kindsFor'])
			->getMock();

		$this->links = $this->getMockBuilder(PartyLinkService::class)
			->disableOriginalConstructor()
			->onlyMethods(['link', 'import', 'end'])
			->getMock();

		return new PartyKindController(
			$this->createMock(IRequest::class),
			$this->registry,
			$this->links,
		);
	}//end controller()

	/**
	 * Ending a link carries the service's status, and the body does not keep
	 * the `status` key the service used to carry it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-kinds-accepted-per-case-type/specs/party-kind-registry/spec.md#requirement-a-kind-declared-single-shall-refuse-a-second-holder-on-one-record-req-pkr-005
	 */
	public function testEndLinkCarriesTheServiceStatus(): void {
		$controller = $this->controller();
		$this->links->method('end')->willReturn(['status' => 200, 'ended' => 'link-1']);

		$response = $controller->endLink(linkId: 'link-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['ended' => 'link-1'], $response->getData());
	}//end testEndLinkCarriesTheServiceStatus()

	/**
	 * A refused end keeps its refusal status rather than answering 200 with an
	 * error body.
	 *
	 * @return void
	 */
	public function testARefusedEndIsNotA200(): void {
		$controller = $this->controller();
		$this->links->method('end')->willReturn(['status' => 404, 'error' => 'That link could not be read.']);

		$response = $controller->endLink(linkId: 'link-1');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame('That link could not be read.', $response->getData()['error']);
	}//end testARefusedEndIsNotA200()

	/**
	 * The index says whether the record type declared an acceptance list, so a
	 * picker can tell "these two kinds" from "nothing was declared".
	 *
	 * @return void
	 */
	public function testTheIndexSaysWhetherAcceptanceWasDeclared(): void {
		$controller = $this->controller();
		$this->registry->method('acceptanceFor')->willReturn(null);
		$this->registry->method('kindsFor')->willReturn([['code' => 'aanvrager']]);

		$response = $controller->index(recordType: 'pipelinq:ticket');

		$this->assertSame(200, $response->getStatus());
		$this->assertFalse($response->getData()['declared']);
		$this->assertSame('aanvrager', $response->getData()['kinds'][0]['code']);
	}//end testTheIndexSaysWhetherAcceptanceWasDeclared()
	/**
	 * A link passes every field through and answers at the service status.
	 *
	 * @return void
	 */
	public function testLinkPassesTheFieldsAndCarriesTheStatus(): void {
		$controller = $this->controller();
		$this->links->expects($this->once())->method('link')
			->with('pipelinq:ticket', 't-1', 'party-9', 'aanvrager')
			->willReturn(['status' => 201, 'link' => ['id' => 'link-7']]);

		$response = $controller->link(recordType: 'pipelinq:ticket', recordId: 't-1', party: 'party-9', kind: 'aanvrager');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(['link' => ['id' => 'link-7']], $response->getData());
	}//end testLinkPassesTheFieldsAndCarriesTheStatus()

	/**
	 * An import passes the rows through and keeps a refusal status.
	 *
	 * @return void
	 */
	public function testImportPassesTheRowsAndKeepsARefusal(): void {
		$controller = $this->controller();
		$rows = [['party' => 'p-1', 'kind' => 'aanvrager']];
		$this->links->expects($this->once())->method('import')
			->with('pipelinq:ticket', 't-1', $rows)
			->willReturn(['status' => 422, 'error' => 'Kind not accepted.']);

		$response = $controller->import(recordType: 'pipelinq:ticket', recordId: 't-1', rows: $rows);

		$this->assertSame(422, $response->getStatus());
		$this->assertSame(['error' => 'Kind not accepted.'], $response->getData());
	}//end testImportPassesTheRowsAndKeepsARefusal()

}//end class
