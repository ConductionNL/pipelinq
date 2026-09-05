<?php

/**
 * Unit tests for CampaignController.
 *
 * Covers:
 * - an anonymous caller is refused, and an unprivileged one too
 * - the report is returned as the service built it
 * - an unknown campaign is a 404
 * - each of Portaliq's failure codes reaches the caller in the body, with
 *   a status that says whose problem it is
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-creates-its-landing-page-in-portaliq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\CampaignController;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\CampaignReportService;
use OCA\Pipelinq\Service\LandingPageProvisioningService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CampaignController.
 *
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-one-campaign-report-page
 */
class CampaignControllerTest extends TestCase {

	/**
	 * Build a controller over mocked collaborators.
	 *
	 * @param bool $signedIn Whether a user is signed in.
	 * @param bool $privileged Whether that user is privileged.
	 * @param array<string, mixed>|null $report What the report service answers.
	 * @param array<string, mixed>|null $landingPage What the provisioning service answers.
	 *
	 * @return CampaignController
	 */
	private function build(
		bool $signedIn = true,
		bool $privileged = true,
		?array $report = ['campaign' => ['id' => 'camp-1']],
		?array $landingPage = null,
	): CampaignController {
		$reportService = $this->createMock(CampaignReportService::class);
		$reportService->method('forCampaign')->willReturn($report);

		$provisioning = $this->createMock(LandingPageProvisioningService::class);
		$provisioning->method('createFor')->willReturn(
			($landingPage ?? ['error' => '', 'portal' => 'open-tilburg', 'route' => '/campagne/x', 'pageId' => 'page-1', 'publicUrl' => '', 'formId' => 'form-1'])
		);

		$session = $this->createMock(IUserSession::class);
		if ($signedIn === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('marketer');
			$session->method('getUser')->willReturn($user);
		} else {
			$session->method('getUser')->willReturn(null);
		}

		$policy = $this->createMock(ObjectOwnerAccessPolicy::class);
		$policy->method('isPrivileged')->willReturn($privileged);

		return new CampaignController(
			'pipelinq',
			$this->createMock(IRequest::class),
			$reportService,
			$provisioning,
			$session,
			$policy
		);
	}//end build()

	/**
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefused(): void {
		$response = $this->build(signedIn: false)->report(id: 'camp-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testAnAnonymousCallerIsRefused()

	/**
	 * @return void
	 */
	public function testAnUnprivilegedCallerIsForbidden(): void {
		$response = $this->build(privileged: false)->report(id: 'camp-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testAnUnprivilegedCallerIsForbidden()

	/**
	 * @return void
	 */
	public function testTheLandingPageActionIsGuardedToo(): void {
		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$this->build(privileged: false)->createLandingPage(id: 'camp-1')->getStatus()
		);
	}//end testTheLandingPageActionIsGuardedToo()

	/**
	 * @return void
	 */
	public function testTheReportIsReturnedAsBuilt(): void {
		$response = $this->build()->report(id: 'camp-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['campaign' => ['id' => 'camp-1']], $response->getData());
	}//end testTheReportIsReturnedAsBuilt()

	/**
	 * @return void
	 */
	public function testAnUnknownCampaignIsNotFound(): void {
		$response = $this->build(report: null)->report(id: 'nope');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testAnUnknownCampaignIsNotFound()

	/**
	 * @return void
	 */
	public function testACreatedPageIsReturnedWithItsIds(): void {
		$response = $this->build()->createLandingPage(id: 'camp-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('form-1', $response->getData()['formId']);
	}//end testACreatedPageIsReturnedWithItsIds()

	/**
	 * Portaliq's code always travels in the body: it is what tells the
	 * marketer which of the two forms to go and fix.
	 *
	 * @param string $error The code Portaliq answered with.
	 * @param int $status The status the controller pairs it with.
	 *
	 * @return void
	 *
	 * @dataProvider portaliqErrors
	 */
	public function testPortaliqErrorsReachTheCallerVerbatim(string $error, int $status): void {
		$response = $this->build(
			landingPage: ['error' => $error, 'portal' => '', 'route' => '', 'pageId' => '', 'publicUrl' => '', 'formId' => '']
		)->createLandingPage(id: 'camp-1');

		$this->assertSame($status, $response->getStatus());
		$this->assertSame($error, $response->getData()['error']);
	}//end testPortaliqErrorsReachTheCallerVerbatim()

	/**
	 * Every failure code the contract names, and the one Pipelinq adds.
	 *
	 * @return array<string, array{0: string, 1: int}>
	 */
	public static function portaliqErrors(): array {
		return [
			'unknown portal' => ['unknown_portal', Http::STATUS_NOT_FOUND],
			'duplicate route' => ['duplicate_route', Http::STATUS_CONFLICT],
			'invalid article' => ['invalid_article', Http::STATUS_UNPROCESSABLE_ENTITY],
			'invalid form' => ['invalid_form', Http::STATUS_UNPROCESSABLE_ENTITY],
			'write failed' => ['write_failed', Http::STATUS_BAD_GATEWAY],
			'portaliq missing' => ['portaliq_missing', Http::STATUS_NOT_IMPLEMENTED],
		];
	}//end portaliqErrors()
}//end class
