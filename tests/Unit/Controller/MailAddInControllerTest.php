<?php

/**
 * Unit tests for MailAddInController.
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
 * @spec openspec/changes/contact-moments-mail-add-in/specs/mail-add-in/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Pipelinq\Controller\MailAddInController;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\MailAddIn\AddInManifest;
use OCA\Pipelinq\Service\MailAddIn\OpenMailService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OutOfBoundsException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * The pane's three calls: who may make them, and what each refusal says.
 *
 * WHY THE HEADER TESTS EXIST. These routes skip the CSRF token. What stands in
 * for it is that a call must carry its own credentials, which a request started
 * by another site cannot. A call on a browser session alone must do nothing.
 *
 * @covers \OCA\Pipelinq\Controller\MailAddInController
 *
 * @spec openspec/changes/contact-moments-mail-add-in/specs/mail-add-in/spec.md
 */
class MailAddInControllerTest extends TestCase {
	private OpenMailService&MockObject $openMail;
	private ObjectOwnerAccessPolicy&MockObject $policy;
	private AddInManifest&MockObject $addIn;
	private IInitialState&MockObject $initialState;

	/**
	 * Build the controller.
	 *
	 * @param array<string, mixed> $params The request body.
	 * @param string $authorization The `Authorization` header.
	 * @param bool $signedIn Whether a user is signed in.
	 * @param bool $privileged Whether that user may add contacts.
	 *
	 * @return MailAddInController
	 */
	private function controller(array $params = [], string $authorization = 'Basic YW5uYTphcHAtcGFzcw==', bool $signedIn = true, bool $privileged = true): MailAddInController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($params[$key] ?? $default));
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => match ($name) {
				'Authorization' => $authorization,
				default => '',
			}
		);

		$user = null;
		if ($signedIn === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('anna');
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->openMail = $this->createMock(OpenMailService::class);
		$this->policy = $this->createMock(ObjectOwnerAccessPolicy::class);
		$this->policy->method('isPrivileged')->with('anna')->willReturn($privileged);

		$this->addIn = $this->createMock(AddInManifest::class);
		$this->initialState = $this->createMock(IInitialState::class);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route): string => 'https://cloud.example.nl/route/' . $route
		);

		return new MailAddInController(
			$request,
			$session,
			$this->openMail,
			$this->policy,
			new NullLogger(),
			$this->addIn,
			$this->initialState,
			$urls
		);
	}//end controller()

	/**
	 * Every action is open to a signed-in user and skips the CSRF token, as
	 * a caller with an app password needs.
	 *
	 * @return void
	 */
	public function testEveryActionDeclaresItsAuthPosture(): void {
		foreach (['lookup', 'contact', 'log'] as $action) {
			$method = new ReflectionMethod(MailAddInController::class, $action);
			$this->assertCount(1, $method->getAttributes(NoAdminRequired::class), $action);
			$this->assertCount(1, $method->getAttributes(NoCSRFRequired::class), $action);
		}
	}//end testEveryActionDeclaresItsAuthPosture()

	/**
	 * A call on a browser session alone does nothing, on any of the three.
	 *
	 * @return void
	 */
	public function testACallWithoutItsOwnCredentialsDoesNothing(): void {
		foreach (['lookup', 'contact', 'log'] as $action) {
			$controller = $this->controller(params: ['email' => 'anna@example.nl'], authorization: '');
			$this->openMail->expects($this->never())->method($this->anything());

			$this->assertSame(Http::STATUS_FORBIDDEN, $controller->$action()->getStatus(), $action);
		}
	}//end testACallWithoutItsOwnCredentialsDoesNothing()

	/**
	 * Nobody signed in: 401, and nothing runs.
	 *
	 * @return void
	 */
	public function testNobodySignedInIsRefused(): void {
		foreach (['lookup', 'contact', 'log'] as $action) {
			$controller = $this->controller(signedIn: false);
			$this->openMail->expects($this->never())->method($this->anything());

			$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->$action()->getStatus(), $action);
		}
	}//end testNobodySignedInIsRefused()

	/**
	 * Lookup hands the address to the service and returns its answer; text
	 * that is no address is a 400.
	 *
	 * @return void
	 */
	public function testLookupAnswersAndRefuses(): void {
		$controller = $this->controller(params: ['email' => 'anna@example.nl']);
		$this->openMail->expects($this->once())->method('lookup')->with('anna@example.nl')
			->willReturn(['matches' => [], 'organisation' => null]);
		$response = $controller->lookup();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['matches' => [], 'organisation' => null], $response->getData());

		$controller = $this->controller(params: ['email' => 'nope']);
		$this->openMail->method('lookup')->willThrowException(new InvalidArgumentException('email is not an email address'));
		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->lookup()->getStatus());
	}//end testLookupAnswersAndRefuses()

	/**
	 * Add as contact is for colleagues who work with clients.
	 *
	 * @return void
	 */
	public function testAddingAContactNeedsTheRight(): void {
		$controller = $this->controller(params: ['name' => 'Piet', 'email' => 'piet@example.nl'], privileged: false);
		$this->openMail->expects($this->never())->method('addSender');

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->contact()->getStatus());
	}//end testAddingAContactNeedsTheRight()

	/**
	 * Add as contact: 201 with the contact, 404 for a client the caller may
	 * not read, 400 for a refused form, 500 when the create breaks.
	 *
	 * @return void
	 */
	public function testAddingAContactAnswersAndRefuses(): void {
		$params = ['name' => 'Piet', 'email' => 'piet@example.nl', 'client' => 'k-1'];

		$controller = $this->controller(params: $params);
		$this->openMail->expects($this->once())->method('addSender')->with('Piet', 'piet@example.nl', 'k-1')->willReturn(['id' => 'c-9']);
		$response = $controller->contact();
		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['object' => ['id' => 'c-9']], $response->getData());

		$refusals = [
			Http::STATUS_NOT_FOUND => new OutOfBoundsException('client not found'),
			Http::STATUS_BAD_REQUEST => new RuntimeException('Could not provision the Nextcloud contact'),
			Http::STATUS_INTERNAL_SERVER_ERROR => new \Error('boom'),
		];
		foreach ($refusals as $status => $thrown) {
			$controller = $this->controller(params: $params);
			$this->openMail->method('addSender')->willThrowException($thrown);
			$this->assertSame($status, $controller->contact()->getStatus());
		}

		$controller = $this->controller(params: $params);
		$this->openMail->method('addSender')->willThrowException(new InvalidArgumentException('A name and an email address are required.'));
		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->contact()->getStatus());
	}//end testAddingAContactAnswersAndRefuses()

	/**
	 * Log this mail: 201 with the contact moment, 404 for a record the
	 * caller may not read, 400 for a refused field, 500 when the save breaks.
	 *
	 * @return void
	 */
	public function testLoggingAMailAnswersAndRefuses(): void {
		$params = ['entityType' => 'contact', 'entityId' => 'c-1', 'subject' => 'Offerte', 'sentAt' => '2026-10-12T09:30:00Z', 'preview' => 'Beste'];

		$controller = $this->controller(params: $params);
		$this->openMail->expects($this->once())->method('logMail')
			->with('contact', 'c-1', 'Offerte', '2026-10-12T09:30:00Z', 'Beste')->willReturn(['id' => 'cm-1']);
		$response = $controller->log();
		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['contactMoment' => ['id' => 'cm-1']], $response->getData());

		$refusals = [
			Http::STATUS_NOT_FOUND => new OutOfBoundsException('record not found'),
			Http::STATUS_BAD_REQUEST => new InvalidArgumentException('subject is required'),
			Http::STATUS_INTERNAL_SERVER_ERROR => new RuntimeException('Ticket register or schema not configured.'),
		];
		foreach ($refusals as $status => $thrown) {
			$controller = $this->controller(params: $params);
			$this->openMail->method('logMail')->willThrowException($thrown);
			$this->assertSame($status, $controller->log()->getStatus());
		}
	}//end testLoggingAMailAnswersAndRefuses()

	/**
	 * The manifest and the pane are public pages without a CSRF token:
	 * Microsoft 365 fetches the one, Outlook frames the other, neither with
	 * a Nextcloud session.
	 *
	 * @return void
	 */
	public function testTheManifestAndThePaneArePublic(): void {
		foreach (['manifest', 'taskpane'] as $action) {
			$method = new ReflectionMethod(MailAddInController::class, $action);
			$this->assertCount(1, $method->getAttributes(PublicPage::class), $action);
			$this->assertCount(1, $method->getAttributes(NoCSRFRequired::class), $action);
		}
	}//end testTheManifestAndThePaneArePublic()

	/**
	 * The manifest is served as XML, as the manifest builder made it.
	 *
	 * @return void
	 */
	public function testTheManifestIsServedAsXml(): void {
		$controller = $this->controller(authorization: '', signedIn: false);
		$this->addIn->method('xml')->willReturn('<OfficeApp/>');
		$this->openMail->expects($this->never())->method($this->anything());

		$response = $controller->manifest();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('<OfficeApp/>', $response->getData());
		// Response::getHeaders() reaches into the server container; read the
		// headers the controller set directly.
		$headers = (new ReflectionProperty(Response::class, 'headers'))->getValue($response);
		$this->assertSame('application/xml; charset=utf-8', $headers['Content-Type']);
		$this->assertStringContainsString('pipelinq-outlook-add-in.xml', $headers['Content-Disposition']);
	}//end testTheManifestIsServedAsXml()

	/**
	 * The pane may be framed by the Outlook hosts only (besides the
	 * instance itself), may load Office.js, and gets the URLs it calls.
	 *
	 * @return void
	 */
	public function testThePaneIsFramedByOutlookOnly(): void {
		$controller = $this->controller(authorization: '', signedIn: false);
		$this->initialState->expects($this->once())->method('provideInitialState')->with(
			'mail-add-in',
			[
				'loginFlowUrl' => 'https://cloud.example.nl/route/core.ClientFlowLoginV2.init',
				'lookupUrl' => 'https://cloud.example.nl/route/pipelinq.mailAddIn.lookup',
				'contactUrl' => 'https://cloud.example.nl/route/pipelinq.mailAddIn.contact',
				'logUrl' => 'https://cloud.example.nl/route/pipelinq.mailAddIn.log',
				'appUrl' => 'https://cloud.example.nl/route/pipelinq.dashboard.page',
			]
		);

		$response = $controller->taskpane();

		$this->assertSame('mail-add-in', $response->getTemplateName());
		$this->assertSame(TemplateResponse::RENDER_AS_BASE, $response->getRenderAs());

		$policy = $response->getContentSecurityPolicy()->buildPolicy();
		$this->assertMatchesRegularExpression('/frame-ancestors ([^;]+)/', $policy);
		preg_match('/frame-ancestors ([^;]+)/', $policy, $match);
		$ancestors = explode(' ', trim($match[1]));
		sort($ancestors);
		$expected = array_merge(["'self'"], AddInManifest::FRAME_HOSTS);
		sort($expected);
		$this->assertSame($expected, $ancestors);
		$this->assertSame(
			['https://outlook.live.com', 'https://outlook.office.com', 'https://outlook.office365.com'],
			array_values(array_filter($ancestors, static fn (string $host): bool => $host !== "'self'"))
		);

		preg_match('/script-src ([^;]+)/', $policy, $script);
		$this->assertContains('https://appsforoffice.microsoft.com', explode(' ', trim($script[1])));
	}//end testThePaneIsFramedByOutlookOnly()
}//end class
