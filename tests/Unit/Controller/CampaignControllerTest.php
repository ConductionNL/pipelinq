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
 * - a create refused for an unknown source answers 422 with the value and
 *   the allowed list, so the form can name both
 * - createdBy and utmCampaign are stripped from the body: a browser cannot
 *   claim authorship, nor set the minted campaign value
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
use OCA\Pipelinq\Service\CampaignService;
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
	 * What the controller handed to CampaignService::save().
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $savedPayload = null;

	/**
	 * Build a controller over mocked collaborators.
	 *
	 * @param bool $signedIn Whether a user is signed in.
	 * @param bool $privileged Whether that user is privileged.
	 * @param array<string, mixed>|null $report What the report service answers.
	 * @param array<string, mixed>|null $landingPage What the provisioning service answers.
	 * @param array<string, mixed>|null $save What CampaignService::save() answers.
	 * @param array<string, mixed> $params What the request body carries.
	 *
	 * @return CampaignController
	 */
	private function build(
		bool $signedIn = true,
		bool $privileged = true,
		?array $report = ['campaign' => ['id' => 'camp-1']],
		?array $landingPage = null,
		?array $save = null,
		array $params = [],
	): CampaignController {
		$reportService = $this->createMock(CampaignReportService::class);
		$reportService->method('forCampaign')->willReturn($report);

		$this->savedPayload = null;
		$campaigns = $this->createMock(CampaignService::class);
		$campaigns->method('vocabularies')->willReturn(['sources' => ['email'], 'mediums' => ['email']]);
		$campaigns->method('save')->willReturnCallback(
			function (array $payload, ?string $id = null, string $uid = '') use ($save): array {
				$this->savedPayload = ['payload' => $payload, 'id' => $id, 'uid' => $uid];
				return ($save ?? ['error' => '', 'value' => '', 'allowed' => [], 'campaign' => ['uuid' => 'camp-1']]);
			}
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

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
			$request,
			$reportService,
			$campaigns,
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
	 * @return void
	 */
	public function testTheVocabulariesAreReturnedForTheForm(): void {
		$response = $this->build()->vocabularies();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['email'], $response->getData()['sources']);
	}//end testTheVocabulariesAreReturnedForTheForm()

	/**
	 * @return void
	 */
	public function testCreateSavesThroughTheServiceAndStampsTheCaller(): void {
		$controller = $this->build(params: ['name' => 'Webinar', 'utmSource' => 'email']);

		$response = $controller->create();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('Webinar', $this->savedPayload['payload']['name']);
		$this->assertNull($this->savedPayload['id']);
		$this->assertSame('marketer', $this->savedPayload['uid']);
	}//end testCreateSavesThroughTheServiceAndStampsTheCaller()

	/**
	 * A browser may not claim authorship, nor set the minted campaign
	 * value: both are the server's to decide (ADR-005).
	 *
	 * @return void
	 */
	public function testTheBodyCannotSetCreatedByOrTheCampaignValue(): void {
		$controller = $this->build(
			params: ['name' => 'Webinar', 'createdBy' => 'someone-else', 'utmCampaign' => 'hand-picked', 'id' => 'x']
		);

		$controller->create();

		$this->assertArrayNotHasKey('createdBy', $this->savedPayload['payload']);
		$this->assertArrayNotHasKey('utmCampaign', $this->savedPayload['payload']);
		$this->assertArrayNotHasKey('id', $this->savedPayload['payload']);
	}//end testTheBodyCannotSetCreatedByOrTheCampaignValue()

	/**
	 * @return void
	 */
	public function testAnUnknownSourceIsRefusedWithTheValueAndTheAllowedList(): void {
		$controller = $this->build(
			save: ['error' => 'unknown_utm_source', 'value' => 'Beurs', 'allowed' => ['email', 'beurs'], 'campaign' => null],
			params: ['name' => 'Beursactie', 'utmSource' => 'Beurs']
		);

		$response = $controller->create();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame('unknown_utm_source', $response->getData()['error']);
		$this->assertSame('Beurs', $response->getData()['value']);
		$this->assertSame(['email', 'beurs'], $response->getData()['allowed']);
	}//end testAnUnknownSourceIsRefusedWithTheValueAndTheAllowedList()

	/**
	 * @return void
	 */
	public function testUpdatePassesTheIdThrough(): void {
		$controller = $this->build(params: ['name' => 'Webinar']);

		$controller->update(id: 'camp-9');

		$this->assertSame('camp-9', $this->savedPayload['id']);
	}//end testUpdatePassesTheIdThrough()

	/**
	 * @return void
	 */
	public function testTheWriteRoutesAreGuardedToo(): void {
		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$this->build(privileged: false)->create()->getStatus()
		);
		$this->assertSame(
			Http::STATUS_UNAUTHORIZED,
			$this->build(signedIn: false)->vocabularies()->getStatus()
		);
	}//end testTheWriteRoutesAreGuardedToo()

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
