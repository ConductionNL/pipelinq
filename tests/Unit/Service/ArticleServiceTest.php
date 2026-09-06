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
 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ArticleService;
use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the article lifecycle, its derived usages and its rendering.
 *
 * The object store is a real in-memory subclass rather than a mock returning
 * canned rows. Create, publish and republish each read back what the step
 * before them WROTE, so a mock answering from a fixture would agree with the
 * caller whatever the service actually stored, and the rule under test here
 * is precisely that a second publish does NOT move a stored value.
 *
 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
 */
class ArticleServiceTest extends TestCase {
	/**
	 * The in-memory object store the service reads and writes.
	 *
	 * @var ListObjectStore
	 */
	private ListObjectStore $store;

	/**
	 * The service under test.
	 *
	 * @var ArticleService
	 */
	private ArticleService $service;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = $this->makeStore();
		$this->service = new ArticleService($this->store);
	}//end setUp()

	/**
	 * A new article starts as a draft with the author stamped from the
	 * session and no publication moment.
	 *
	 * @return void
	 */
	public function testCreateStartsAsADraftWithTheAuthorStamped(): void {
		$result = $this->service->createArticle(
			payload: ['title' => 'OpenRegister 3.0 is uit', 'body' => '## Wat er nieuw is'],
			authorUid: 'marieke',
		);

		$this->assertArrayNotHasKey('error', $result);
		$this->assertSame('draft', $result['article']['status']);
		$this->assertSame('marieke', $result['article']['author']);
		$this->assertSame('openregister-3-0-is-uit', $result['article']['slug']);
		$this->assertSame('', (string)($result['article']['publishedAt'] ?? ''));
	}//end testCreateStartsAsADraftWithTheAuthorStamped()

	/**
	 * An article with no title is refused and nothing is stored.
	 *
	 * @return void
	 */
	public function testCreateWithoutATitleIsRefused(): void {
		$result = $this->service->createArticle(payload: ['title' => '  '], authorUid: 'marieke');

		$this->assertArrayHasKey('error', $result);
		$this->assertSame(0, $this->service->listArticles(page: 1, limit: 10)['pagination']['total']);
	}//end testCreateWithoutATitleIsRefused()

	/**
	 * A second article cannot claim a slug the first already holds.
	 *
	 * @return void
	 */
	public function testDuplicateSlugIsRefused(): void {
		$this->service->createArticle(payload: ['title' => 'Nieuwe release'], authorUid: 'marieke');

		$second = $this->service->createArticle(
			payload: ['title' => 'Iets anders', 'slug' => 'nieuwe-release'],
			authorUid: 'marieke',
		);

		$this->assertArrayHasKey('error', $second);
		$this->assertSame(1, $this->service->listArticles(page: 1, limit: 10)['pagination']['total']);
	}//end testDuplicateSlugIsRefused()

	/**
	 * A client cannot claim an article was written by a person, nor that it
	 * was written by an agent: both mark fields come from the write path.
	 *
	 * @return void
	 */
	public function testTheAgentMarkIsNeverTakenFromTheRequest(): void {
		$byPerson = $this->service->createArticle(
			payload: ['title' => 'Door een mens', 'agentAuthored' => true, 'agentAuthoredBy' => 'not-an-agent'],
			authorUid: 'marieke',
		);
		$byAgent = $this->service->createArticle(
			payload: ['title' => 'Door een agent', 'agentAuthored' => false],
			authorUid: 'marieke',
			agent: 'hermiq:marketing-editor',
		);

		$this->assertFalse($byPerson['article']['agentAuthored']);
		$this->assertSame('', $byPerson['article']['agentAuthoredBy']);
		$this->assertTrue($byAgent['article']['agentAuthored']);
		$this->assertSame('hermiq:marketing-editor', $byAgent['article']['agentAuthoredBy']);
	}//end testTheAgentMarkIsNeverTakenFromTheRequest()

	/**
	 * Publishing a reviewed article stamps the publication moment.
	 *
	 * @return void
	 */
	public function testPublishStampsThePublicationMoment(): void {
		$id = $this->seedArticle(title: 'Te publiceren');
		$this->service->applyTransition(articleId: $id, transition: 'submitForReview');

		$published = $this->service->publishArticle(articleId: $id);

		$this->assertSame('published', $published['article']['status']);
		$this->assertNotSame('', (string)$published['article']['publishedAt']);
	}//end testPublishStampsThePublicationMoment()

	/**
	 * Publishing twice keeps the moment a reader already saw.
	 *
	 * @return void
	 */
	public function testPublishingTwiceKeepsTheFirstMoment(): void {
		$id = $this->seedArticle(title: 'Twee keer gepubliceerd');
		$first = $this->service->publishArticle(articleId: $id);
		$firstMoment = (string)$first['article']['publishedAt'];

		$this->service->applyTransition(articleId: $id, transition: 'archive');
		$this->service->applyTransition(articleId: $id, transition: 'restore');
		$second = $this->service->publishArticle(articleId: $id);

		$this->assertSame($firstMoment, (string)$second['article']['publishedAt']);
	}//end testPublishingTwiceKeepsTheFirstMoment()

	/**
	 * An archived article cannot be published straight back into a mailing.
	 *
	 * @return void
	 */
	public function testAnArchivedArticleCannotBePublishedDirectly(): void {
		$id = $this->seedArticle(title: 'Gearchiveerd');
		$this->service->archiveArticle(articleId: $id);

		$refused = $this->service->publishArticle(articleId: $id);

		$this->assertArrayHasKey('error', $refused);
		$this->assertSame('archived', $this->service->getArticleById(articleId: $id)['status']);
	}//end testAnArchivedArticleCannotBePublishedDirectly()

	/**
	 * Usages name the template that embeds the article and the blast built
	 * on that template.
	 *
	 * @return void
	 */
	public function testUsagesNameTheTemplateAndTheBlast(): void {
		$id = $this->seedArticle(title: 'Gebruikt artikel');
		$this->store->save(
			schemaSlug: 'campaignTemplate',
			payload: ['name' => 'Nieuwsbrief september', 'channel' => 'email', 'articleIds' => [$id]],
			id: 'template-1',
		);
		$this->store->save(
			schemaSlug: 'blast',
			payload: ['name' => 'Septemberzending', 'status' => 'sent', 'templateId' => 'template-1'],
			id: 'blast-1',
		);

		$usages = $this->service->listUsages(articleId: $id);

		$this->assertSame(1, $usages['counts']['template']);
		$this->assertSame(1, $usages['counts']['blast']);
		$this->assertSame(['Nieuwsbrief september', 'Septemberzending'], array_column($usages['data'], 'name'));
	}//end testUsagesNameTheTemplateAndTheBlast()

	/**
	 * Removing the reference removes the usage, and nothing was written to
	 * the article to make that true.
	 *
	 * @return void
	 */
	public function testRemovingTheReferenceRemovesTheUsage(): void {
		$id = $this->seedArticle(title: 'Losgekoppeld artikel');
		$this->store->save(
			schemaSlug: 'campaignTemplate',
			payload: ['name' => 'Nieuwsbrief', 'channel' => 'email', 'articleIds' => [$id]],
			id: 'template-1',
		);
		$before = $this->service->getArticleById(articleId: $id);

		$this->store->save(
			schemaSlug: 'campaignTemplate',
			payload: ['name' => 'Nieuwsbrief', 'channel' => 'email', 'articleIds' => []],
			id: 'template-1',
		);

		$this->assertSame([], $this->service->listUsages(articleId: $id)['data']);
		$this->assertSame($before, $this->service->getArticleById(articleId: $id));
	}//end testRemovingTheReferenceRemovesTheUsage()

	/**
	 * An article no one references reports an empty list, not an error.
	 *
	 * @return void
	 */
	public function testAnUnusedArticleReportsAnEmptyList(): void {
		$id = $this->seedArticle(title: 'Ongebruikt artikel');

		$usages = $this->service->listUsages(articleId: $id);

		$this->assertSame([], $usages['data']);
		$this->assertSame(0, $usages['counts']['template']);
	}//end testAnUnusedArticleReportsAnEmptyList()

	/**
	 * The HTML block carries every article's title, summary and hero, in the
	 * order the template named them, and the marker is gone.
	 *
	 * @return void
	 */
	public function testHtmlBlockRendersTitleSummaryAndHero(): void {
		$body = $this->service->expandArticlesMarker(
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
		$body = $this->service->expandArticlesMarker(
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

		$html = $this->service->renderArticlesBlock(articles: [$linked], format: ArticleService::FORMAT_HTML);
		$text = $this->service->renderArticlesBlock(articles: [$linked], format: ArticleService::FORMAT_TEXT);

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

		$html = $this->service->renderArticlesBlock(articles: [$unlinked], format: ArticleService::FORMAT_HTML);
		$text = $this->service->renderArticlesBlock(articles: [$unlinked], format: ArticleService::FORMAT_TEXT);

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

		$html = $this->service->renderArticlesBlock(articles: [$article], format: ArticleService::FORMAT_HTML);

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

		$rendered = $this->service->expandArticlesMarker(
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
		$rendered = $this->service->expandArticlesMarker(
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

		$html = $this->service->renderArticlesBlock(articles: [$article], format: ArticleService::FORMAT_HTML);

		$this->assertStringNotContainsString('<script>', $html);
		$this->assertStringContainsString('&lt;script&gt;', $html);
	}//end testArticleValuesAreEscapedInTheHtmlBlock()

	/**
	 * Store an article and return its id.
	 *
	 * @param string $title The title.
	 *
	 * @return string The stored article's id.
	 */
	private function seedArticle(string $title): string {
		$created = $this->service->createArticle(payload: ['title' => $title], authorUid: 'marieke');

		return $this->store->idOf(payload: $created['article']);
	}//end seedArticle()

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
	 * A store backed by a plain array, so each step reads what the last wrote.
	 *
	 * @return ListObjectStore The in-memory store.
	 */
	private function makeStore(): ListObjectStore {
		return new class(
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class),
		) extends ListObjectStore {
			/** @var array<string, array<string, array<string, mixed>>> */
			public array $rows = [];

			/** @var int */
			private int $seq = 0;

			/**
			 * @param string $configKey Ignored.
			 * @param string $default The slug.
			 * @return string The slug.
			 */
			public function schemaSlug(string $configKey, string $default): string {
				return $default;
			}

			/**
			 * @param string $schemaSlug The schema.
			 * @param string $id The id.
			 * @return array<string, mixed>|null The row.
			 */
			public function find(string $schemaSlug, string $id): ?array {
				return ($this->rows[$schemaSlug][$id] ?? null);
			}

			/**
			 * @param string $schemaSlug The schema.
			 * @param array<string, string> $filters Field-value pairs.
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function findAll(string $schemaSlug, array $filters = []): array {
				$out = [];
				foreach (($this->rows[$schemaSlug] ?? []) as $row) {
					foreach ($filters as $field => $value) {
						if ((string)($row[$field] ?? '') !== (string)$value) {
							continue 2;
						}
					}
					$out[] = $row;
				}
				return $out;
			}

			/**
			 * @param string $schemaSlug The schema.
			 * @param array<string, mixed> $payload The payload.
			 * @param string|null $id Existing id.
			 * @return array<string, mixed>|null The saved row.
			 */
			public function save(string $schemaSlug, array $payload, ?string $id = null): ?array {
				$key = $id;
				if ($key === null || $key === '') {
					$this->seq++;
					$key = $schemaSlug . '-' . $this->seq;
				}
				$payload['id'] = $key;
				$this->rows[$schemaSlug][$key] = $payload;
				return $payload;
			}

			/**
			 * @param array<string, mixed>|null $payload The row.
			 * @return string The id.
			 */
			public function idOf(?array $payload): string {
				return (string)($payload['id'] ?? '');
			}
		};
	}//end makeStore()
}//end class
