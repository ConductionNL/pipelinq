<?php

/**
 * ContactMomentLeafController: listing contact moments and filing them on cases.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\ContactMomentLeafController;
use OCA\Pipelinq\Kernel\Contracts\ContactMomentFilingServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ContactMomentLeafProviderInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Each endpoint passes its arguments through and answers the service's status
 * as the HTTP status, with the status key removed from the body.
 *
 * @covers \OCA\Pipelinq\Controller\ContactMomentLeafController
 */
class ContactMomentLeafControllerTest extends TestCase {

	/**
	 * Leaf provider mock.
	 *
	 * @var ContactMomentLeafProviderInterface&MockObject
	 */
	private ContactMomentLeafProviderInterface&MockObject $provider;

	/**
	 * Filing service mock.
	 *
	 * @var ContactMomentFilingServiceInterface&MockObject
	 */
	private ContactMomentFilingServiceInterface&MockObject $filing;

	/**
	 * The controller under test.
	 *
	 * @var ContactMomentLeafController
	 */
	private ContactMomentLeafController $controller;

	/**
	 * Build the controller over mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->provider = $this->createMock(ContactMomentLeafProviderInterface::class);
		$this->filing = $this->createMock(ContactMomentFilingServiceInterface::class);
		$this->controller = new ContactMomentLeafController(
			$this->createMock(IRequest::class),
			$this->provider,
			$this->filing
		);
	}//end setUp()

	/**
	 * The list is read with the given host, limit and party.
	 *
	 * @return void
	 */
	public function testIndexListsTheHostsMoments(): void {
		$this->provider->expects($this->once())->method('list')
			->with('case-1', 10, 'party-9')
			->willReturn(['results' => [['id' => 'm-1']], 'total' => 1]);

		$response = $this->controller->index('case-1', 10, 'party-9');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['results' => [['id' => 'm-1']], 'total' => 1], $response->getData());
	}//end testIndexListsTheHostsMoments()

	/**
	 * A refusal from the provider becomes the HTTP status.
	 *
	 * @return void
	 */
	public function testIndexAnswersTheProvidersRefusalStatus(): void {
		$this->provider->method('list')->willReturn(['status' => 403, 'error' => 'Forbidden']);

		$response = $this->controller->index('case-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'Forbidden'], $response->getData());
	}//end testIndexAnswersTheProvidersRefusalStatus()

	/**
	 * Filing on another case passes both ids through.
	 *
	 * @return void
	 */
	public function testFileOnAlsoCasePassesBothIds(): void {
		$this->filing->expects($this->once())->method('fileOnAlsoCase')
			->with('m-1', 'case-2')
			->willReturn(['status' => 201, 'cases' => ['case-1', 'case-2']]);

		$response = $this->controller->fileOnAlsoCase('m-1', 'case-2');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['cases' => ['case-1', 'case-2']], $response->getData());
	}//end testFileOnAlsoCasePassesBothIds()

	/**
	 * Unfiling passes the moment, the case and the new primary through.
	 *
	 * @return void
	 */
	public function testUnfileFromCasePassesTheNewPrimary(): void {
		$this->filing->expects($this->once())->method('unfileFromCase')
			->with('m-1', 'case-1', 'case-2')
			->willReturn(['status' => 409, 'error' => 'Pick a new primary case']);

		$response = $this->controller->unfileFromCase('m-1', 'case-1', 'case-2');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(['error' => 'Pick a new primary case'], $response->getData());
	}//end testUnfileFromCasePassesTheNewPrimary()
}//end class
