<?php

/**
 * Unit tests for ArticleService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-articles/spec.md#requirement-pipelinq-reads-marketing-articles-from-portaliq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ArticleService;
use OCA\Pipelinq\Service\Marketing\PortaliqArticleStore;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryRegisters;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for reading portaliq articles and rendering the `{{articles}}` block.
 *
 * The articles come from a real PortaliqArticleStore over an in-memory
 * ObjectService; only the app manager, the container and the logger are
 * mocked.
 *
 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-articles/spec.md#requirement-pipelinq-reads-marketing-articles-from-portaliq
 */
class ArticleServiceTest extends TestCase {
	/**
	 * The in-memory registers.
	 *
	 * @var InMemoryRegisters
	 */
	private InMemoryRegisters $objects;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new InMemoryRegisters();
		$this->logger  = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A template names portaliq articles by id and by slug; both resolve, in
	 * the order the template named them, and a missing one is skipped.
	 *
	 * @return void
	 */
	public function testArticlesAreReadFromPortaliqInTheNamedOrder(): void {
		$first  = $this->objects->put(register: 'portaliq', schema: 'article', row: ['title' => 'Eerste', 'slug' => 'eerste', 'status' => 'published']);
		$this->objects->put(register: 'portaliq', schema: 'article', row: ['title' => 'Tweede', 'slug' => 'tweede', 'status' => 'published']);
		$this->objects->put(register: 'pipelinq', schema: 'article', row: ['uuid' => 'old-1', 'title' => 'Oud']);

		$articles = $this->service(installed: true)->loadArticlesByIds(articleIds: ['tweede', 'old-1', $first['uuid'], '', null]);

		$this->assertSame(['Tweede', 'Eerste'], array_column($articles, 'title'));
	}//end testArticlesAreReadFromPortaliqInTheNamedOrder()

	/**
	 * A template that still names the old pipelinq id resolves through the
	 * moved article's legacyRef.
	 *
	 * @return void
	 */
	public function testAnOldPipelinqIdResolvesThroughTheLegacyRef(): void {
		$this->objects->put(register: 'portaliq', schema: 'article', row: ['title' => 'Verhuisd', 'legacyRef' => 'pipelinq:old-1']);

		$articles = $this->service(installed: true)->loadArticlesByIds(articleIds: ['old-1']);

		$this->assertSame(['Verhuisd'], array_column($articles, 'title'));
	}//end testAnOldPipelinqIdResolvesThroughTheLegacyRef()

	/**
	 * Without portaliq nothing is read, the reason is logged, and the block
	 * renders empty so the send goes ahead.
	 *
	 * @return void
	 */
	public function testWithoutPortaliqTheBlockRendersEmptyAndTheReasonIsLogged(): void {
		$this->objects->put(register: 'portaliq', schema: 'article', row: ['uuid' => 'a1', 'title' => 'Eerste']);
		$this->logger->expects($this->once())
			->method('info')
			->with($this->stringContains('portaliq'), ['articleIds' => 1]);

		$service  = $this->service(installed: false);
		$articles = $service->loadArticlesByIds(articleIds: ['a1']);

		$this->assertSame([], $articles);
		$this->assertSame(
			'<p>Hallo</p><p>Groet</p>',
			$service->expandArticlesMarker(body: '<p>Hallo</p>{{articles}}<p>Groet</p>', articles: $articles, format: ArticleService::FORMAT_HTML)
		);
	}//end testWithoutPortaliqTheBlockRendersEmptyAndTheReasonIsLogged()

	/**
	 * A template naming no articles never asks whether portaliq is there.
	 *
	 * @return void
	 */
	public function testNamingNoArticlesReadsNothing(): void {
		$this->logger->expects($this->never())->method('info');

		$this->assertSame([], $this->service(installed: false)->loadArticlesByIds(articleIds: []));
	}//end testNamingNoArticlesReadsNothing()

	/**
	 * The picker is offered the published portaliq articles only.
	 *
	 * @return void
	 */
	public function testThePickerIsOfferedPublishedPortaliqArticles(): void {
		$this->objects->put(register: 'portaliq', schema: 'article', row: ['uuid' => 'p1', 'title' => 'Klaar', 'status' => 'published']);
		$this->objects->put(register: 'portaliq', schema: 'article', row: ['uuid' => 'd1', 'title' => 'Concept', 'status' => 'draft']);

		$answer = $this->service(installed: true)->publishedArticles();

		$this->assertTrue($answer['available']);
		$this->assertSame(['p1'], array_column($answer['data'], 'uuid'));
	}//end testThePickerIsOfferedPublishedPortaliqArticles()

	/**
	 * Without portaliq the picker is told so and offered nothing.
	 *
	 * @return void
	 */
	public function testWithoutPortaliqThePickerIsOfferedNothing(): void {
		$this->objects->put(register: 'portaliq', schema: 'article', row: ['uuid' => 'p1', 'status' => 'published']);

		$this->assertSame(['available' => false, 'data' => []], $this->service(installed: false)->publishedArticles());
	}//end testWithoutPortaliqThePickerIsOfferedNothing()

	/**
	 * The HTML block carries every article's title, summary and hero, in the
	 * order the template named them, and the marker is gone.
	 *
	 * @return void
	 */
	public function testHtmlBlockRendersTitleSummaryAndHero(): void {
		$body = $this->service(installed: true)->expandArticlesMarker(
			body: '<p>Hallo</p>{{articles}}<p>Groet</p>',
			articles: [$this->articleFixture(title: 'Eerste'), $this->articleFixture(title: 'Tweede')],
			format: ArticleService::FORMAT_HTML,
		);

		$this->assertStringNotContainsString('{{articles}}', $body);
		$this->assertLessThan(strpos($body, 'Tweede'), strpos($body, 'Eerste'));
		$this->assertStringContainsString('<h2>Eerste</h2>', $body);
		$this->assertStringContainsString('Samenvatting van Eerste', $body);
		$this->assertStringContainsString('<img src="https://example.org/hero.png"', $body);
	}//end testHtmlBlockRendersTitleSummaryAndHero()

	/**
	 * The text block carries the title and the summary and no markup.
	 *
	 * @return void
	 */
	public function testTextBlockRendersTitleAndSummary(): void {
		$body = $this->service(installed: true)->expandArticlesMarker(
			body: "Hallo\n\n{{articles}}\n\nGroet",
			articles: [$this->articleFixture(title: 'Eerste')],
			format: ArticleService::FORMAT_TEXT,
		);

		$this->assertStringContainsString('Eerste', $body);
		$this->assertStringContainsString('Samenvatting van Eerste', $body);
		$this->assertStringNotContainsString('<', $body);
	}//end testTextBlockRendersTitleAndSummary()

	/**
	 * An article with a portal page renders a read-more link in the article's
	 * own language; one without renders no link at all.
	 *
	 * @return void
	 */
	public function testArticleWithPortalPageRefRendersReadMoreLink(): void {
		$linked = $this->articleFixture(title: 'Met pagina');
		$linked['portalPageRef'] = 'https://example.org/nieuws/met-pagina';

		$html = $this->service(installed: true)->renderArticlesBlock(articles: [$linked], format: ArticleService::FORMAT_HTML);
		$text = $this->service(installed: true)->renderArticlesBlock(articles: [$linked], format: ArticleService::FORMAT_TEXT);

		$this->assertStringContainsString('href="https://example.org/nieuws/met-pagina"', $html);
		$this->assertStringContainsString('Lees verder', $html);
		$this->assertStringContainsString('Lees verder: https://example.org/nieuws/met-pagina', $text);
	}//end testArticleWithPortalPageRefRendersReadMoreLink()

	/**
	 * Without a portal page there is no link rather than a link that goes
	 * nowhere.
	 *
	 * @return void
	 */
	public function testArticleWithoutPortalPageRefRendersNoLink(): void {
		$unlinked = $this->articleFixture(title: 'Zonder pagina');

		$html = $this->service(installed: true)->renderArticlesBlock(articles: [$unlinked], format: ArticleService::FORMAT_HTML);
		$text = $this->service(installed: true)->renderArticlesBlock(articles: [$unlinked], format: ArticleService::FORMAT_TEXT);

		$this->assertStringNotContainsString('<a href', $html);
		$this->assertStringNotContainsString('Lees verder', $text);
	}//end testArticleWithoutPortalPageRefRendersNoLink()

	/**
	 * A hero stored as a Nextcloud Files path renders no image tag, because
	 * a mail client cannot reach it.
	 *
	 * @return void
	 */
	public function testAFilesPathHeroRendersNoImageTag(): void {
		$article = $this->articleFixture(title: 'Interne afbeelding');
		$article['heroImage'] = '/Photos/Marketing/hero.jpg';

		$html = $this->service(installed: true)->renderArticlesBlock(articles: [$article], format: ArticleService::FORMAT_HTML);

		$this->assertStringNotContainsString('<img', $html);
		$this->assertStringContainsString('<h2>Interne afbeelding</h2>', $html);
	}//end testAFilesPathHeroRendersNoImageTag()

	/**
	 * A body with no marker comes back byte-identical, so a template written
	 * before this change renders exactly as it did.
	 *
	 * @return void
	 */
	public function testTemplateWithoutMarkerIsUnchanged(): void {
		$original = '<p>Hallo {{email}}</p>';

		$rendered = $this->service(installed: true)->expandArticlesMarker(
			body: $original,
			articles: [$this->articleFixture(title: 'Eerste')],
			format: ArticleService::FORMAT_HTML,
		);

		$this->assertSame($original, $rendered);
	}//end testTemplateWithoutMarkerIsUnchanged()

	/**
	 * A template carrying the marker but naming no articles loses the marker
	 * and gains no markup.
	 *
	 * @return void
	 */
	public function testTemplateNamingNoArticlesRendersAnEmptyBlock(): void {
		$rendered = $this->service(installed: true)->expandArticlesMarker(
			body: '<p>Hallo</p>{{articles}}<p>Groet</p>',
			articles: [],
			format: ArticleService::FORMAT_HTML,
		);

		$this->assertSame('<p>Hallo</p><p>Groet</p>', $rendered);
	}//end testTemplateNamingNoArticlesRendersAnEmptyBlock()

	/**
	 * A title carrying HTML is escaped rather than injected into the body.
	 *
	 * @return void
	 */
	public function testArticleValuesAreEscapedInTheHtmlBlock(): void {
		$article = $this->articleFixture(title: '<script>alert(1)</script>');

		$html = $this->service(installed: true)->renderArticlesBlock(articles: [$article], format: ArticleService::FORMAT_HTML);

		$this->assertStringNotContainsString('<script>', $html);
		$this->assertStringContainsString('&lt;script&gt;', $html);
	}//end testArticleValuesAreEscapedInTheHtmlBlock()

	/**
	 * A rendering fixture with everything but a portal page reference.
	 *
	 * @param string $title The title.
	 *
	 * @return array<string, mixed> The article.
	 */
	private function articleFixture(string $title): array {
		return [
			'title' => $title,
			'summary' => ('Samenvatting van ' . $title),
			'heroImage' => 'https://example.org/hero.png',
			'language' => 'nl',
			'status' => 'published',
			'portalPageRef' => '',
		];
	}//end articleFixture()

	/**
	 * The service over the in-memory registers.
	 *
	 * @param bool $installed Whether portaliq is installed.
	 *
	 * @return ArticleService The service.
	 */
	private function service(bool $installed): ArticleService {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(static fn (string $app): bool => $installed === true && $app === 'portaliq');
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objects);

		return new ArticleService(
			new PortaliqArticleStore($container, $apps, $this->createMock(LoggerInterface::class)),
			$this->logger,
		);
	}//end service()

}//end class
