<?php

/**
 * Contract tests for ArticleController.
 *
 * ArticleService is mocked: these tests pin the wire contract (the auth
 * gate, the response envelopes, the status codes) rather than the service's
 * own behaviour, which ArticleServiceTest already covers. Every method is
 * asserted to refuse an unprivileged caller with the one generic message
 * (marketing-article-hub tasks.md 3.1), and the three lifecycle-transition
 * routes (publish, archive, usages) each get a happy-path assertion too.
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
 *
 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\ArticleController;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\ArticleService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ArticleController.
 *
 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
 */
class ArticleControllerTest extends TestCase {
	/**
	 * Mock request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * Mock article service.
	 *
	 * @var ArticleService&MockObject
	 */
	private ArticleService $articles;

	/**
	 * Mock user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * Mock access policy.
	 *
	 * @var ObjectOwnerAccessPolicy&MockObject
	 */
	private ObjectOwnerAccessPolicy $policy;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->articles = $this->createMock(ArticleService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->policy = $this->createMock(ObjectOwnerAccessPolicy::class);
	}//end setUp()

	/**
	 * Build the controller under test.
	 *
	 * @return ArticleController
	 */
	private function buildController(): ArticleController {
		return new ArticleController(
			request: $this->request,
			articles: $this->articles,
			userSession: $this->userSession,
			policy: $this->policy,
		);
	}//end buildController()

	/**
	 * Sign a privileged CRM user in.
	 *
	 * @return void
	 */
	private function signInAsCrmUser(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('marketer-1');
		$this->userSession->method('getUser')->willReturn($user);
		$this->policy->method('isPrivileged')->willReturn(true);
	}//end signInAsCrmUser()

	/**
	 * An unauthenticated caller (no session user) is refused on every route.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
	 */
	public function testEveryRouteRefusesAnUnauthenticatedCaller(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$controller = $this->buildController();

		foreach ($this->routeInvocations() as $label => $call) {
			$response = $call($controller);
			$this->assertSame(
				Http::STATUS_FORBIDDEN,
				$response->getStatus(),
				$label . ' must refuse an unauthenticated caller',
			);
			$this->assertSame(['error' => 'Forbidden'], $response->getData());
		}
	}//end testEveryRouteRefusesAnUnauthenticatedCaller()

	/**
	 * An authenticated caller who is not in the CRM group is refused the
	 * same way as one who is not logged in at all — the two cannot be told
	 * apart from the response.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
	 */
	public function testEveryRouteRefusesAnUnprivilegedCaller(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('outsider-1');
		$this->userSession->method('getUser')->willReturn($user);
		$this->policy->method('isPrivileged')->willReturn(false);
		$controller = $this->buildController();

		foreach ($this->routeInvocations() as $label => $call) {
			$response = $call($controller);
			$this->assertSame(
				Http::STATUS_FORBIDDEN,
				$response->getStatus(),
				$label . ' must refuse an unprivileged caller',
			);
		}
	}//end testEveryRouteRefusesAnUnprivilegedCaller()

	/**
	 * GET /api/articles/{id}/publish's happy path: the service result comes
	 * back as the response body.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-moves-through-a-declared-lifecycle
	 */
	public function testPublishReturnsTheUpdatedArticle(): void {
		$this->signInAsCrmUser();
		$this->articles->method('publishArticle')->with('article-1')->willReturn([
			'article' => ['id' => 'article-1', 'status' => 'published'],
		]);
		$controller = $this->buildController();

		$response = $controller->publish('article-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('published', $response->getData()['article']['status']);
	}//end testPublishReturnsTheUpdatedArticle()

	/**
	 * POST /api/articles/{id}/archive's happy path.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-moves-through-a-declared-lifecycle
	 */
	public function testArchiveReturnsTheUpdatedArticle(): void {
		$this->signInAsCrmUser();
		$this->articles->method('archiveArticle')->with('article-1')->willReturn([
			'article' => ['id' => 'article-1', 'status' => 'archived'],
		]);
		$controller = $this->buildController();

		$response = $controller->archive('article-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('archived', $response->getData()['article']['status']);
	}//end testArchiveReturnsTheUpdatedArticle()

	/**
	 * GET /api/articles/{id}/usages's happy path: the envelope passes through
	 * unaltered.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-reports-where-it-has-been-used
	 */
	public function testUsagesReturnsTheServiceEnvelope(): void {
		$this->signInAsCrmUser();
		$envelope = [
			'data' => [['kind' => 'template', 'id' => 't1', 'name' => 'Nieuwsbrief', 'status' => 'email']],
			'counts' => ['template' => 1, 'blast' => 0],
		];
		$this->articles->method('listUsages')->with('article-1')->willReturn($envelope);
		$controller = $this->buildController();

		$response = $controller->usages('article-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($envelope, $response->getData());
	}//end testUsagesReturnsTheServiceEnvelope()

	/**
	 * show() answers 404 when the service finds nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
	 */
	public function testShowAnswersNotFoundForAMissingArticle(): void {
		$this->signInAsCrmUser();
		$this->articles->method('getArticleById')->with('missing')->willReturn(null);
		$controller = $this->buildController();

		$response = $controller->show('missing');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testShowAnswersNotFoundForAMissingArticle()

	/**
	 * One call per routed method, each returning that call's JSONResponse —
	 * used to assert the shared refusal uniformly across every route.
	 *
	 * @return array<string, callable(ArticleController): \OCP\AppFramework\Http\JSONResponse>
	 */
	private function routeInvocations(): array {
		return [
			'index' => static fn (ArticleController $c) => $c->index(),
			'show' => static fn (ArticleController $c) => $c->show('article-1'),
			'create' => static fn (ArticleController $c) => $c->create(),
			'update' => static fn (ArticleController $c) => $c->update('article-1'),
			'publish' => static fn (ArticleController $c) => $c->publish('article-1'),
			'archive' => static fn (ArticleController $c) => $c->archive('article-1'),
			'transition' => static fn (ArticleController $c) => $c->transition('article-1'),
			'usages' => static fn (ArticleController $c) => $c->usages('article-1'),
		];
	}//end routeInvocations()
}//end class
