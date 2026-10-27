<?php

/**
 * Unit tests for OrganisationEnrichmentController.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\OrganisationEnrichmentController;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\OrganisationEnrichmentService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The enrichment endpoints check the CRM capability before any lookup or write.
 *
 * @spec openspec/changes/organisation-enrichment/specs/client-management/spec.md
 */
class OrganisationEnrichmentControllerTest extends TestCase {
	/**
	 * A controller for a signed-in user.
	 *
	 * @param OrganisationEnrichmentService $service    The service.
	 * @param bool                          $privileged Whether the user may change the CRM.
	 * @param mixed                         $param      What getParam answers.
	 *
	 * @return OrganisationEnrichmentController The controller.
	 */
	private function controller(OrganisationEnrichmentService $service, bool $privileged, mixed $param): OrganisationEnrichmentController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn($param);
		$user = $this->createConfiguredMock(IUser::class, ['getUID' => 'pieter']);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new OrganisationEnrichmentController(
			$request,
			$service,
			$this->createConfiguredMock(IUserSession::class, ['getUser' => $user]),
			$this->createConfiguredMock(ObjectOwnerAccessPolicy::class, ['isPrivileged' => $privileged]),
			$l10n,
		);
	}//end controller()

	/**
	 * A user without the CRM capability is refused before any lookup or write.
	 *
	 * @return void
	 */
	public function testAUserWithoutTheCrmCapabilityIsRefused(): void {
		$service = $this->createMock(OrganisationEnrichmentService::class);
		$service->expects($this->never())->method('propose');
		$service->expects($this->never())->method('apply');

		$controller = $this->controller(service: $service, privileged: false, param: ['c1']);

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->propose()->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->apply()->getStatus());
	}//end testAUserWithoutTheCrmCapabilityIsRefused()

	/**
	 * Proposing without a selection is a 400.
	 *
	 * @return void
	 */
	public function testProposingWithoutASelectionIsRefused(): void {
		$service = $this->createMock(OrganisationEnrichmentService::class);
		$service->expects($this->never())->method('propose');

		$response = $this->controller(service: $service, privileged: true, param: [])->propose();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testProposingWithoutASelectionIsRefused()

	/**
	 * The selection reaches the service as strings.
	 *
	 * @return void
	 */
	public function testTheSelectionReachesTheService(): void {
		$service = $this->createMock(OrganisationEnrichmentService::class);
		$service->expects($this->once())->method('propose')->with(['c1', '2'])->willReturn(['proposals' => [], 'notFound' => []]);

		$response = $this->controller(service: $service, privileged: true, param: ['c1', 2])->propose();

		$this->assertSame(['proposals' => [], 'notFound' => []], $response->getData());
	}//end testTheSelectionReachesTheService()
}//end class
