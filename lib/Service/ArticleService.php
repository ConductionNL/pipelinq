<?php

/**
 * Pipelinq ArticleService.
 *
 * Owns the `article` object: the content hub piece a marketer writes once in
 * markdown and a mailing, a social post and a portal page each reuse.
 *
 * Three things live here and nowhere else, because the schema grammar cannot
 * express any of them. Publishing stamps `publishedAt` exactly once, so a
 * second publish never moves the date a reader already saw. Where an article
 * has been used is derived from the objects that reference it rather than
 * stored on the article, so removing a reference removes the usage without a
 * second write. And the `{{articles}}` block is rendered from one method that
 * both the send path and the preview call, so a marketer's preview is produced
 * by the code that will do the sending.
 *
 * Register access goes through {@see ListObjectStore}, which is the marketing
 * register's plumbing rather than anything list-specific: it takes the schema
 * slug on every call and is the single place carrying the `_rbac` and
 * `_multitenancy` flags every marketing read needs. Copying it for one more
 * schema is the duplication ADR-012 exists to stop.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
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

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Service\Marketing\ListObjectStore;

/**
 * ArticleService — the article lifecycle, its usages and its rendering.
 *
 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) The lifecycle, the derived
 *  usages and the shared renderer are one cohesive surface; splitting them
 *  would put the renderer somewhere the send path has to reach across for.
 */
class ArticleService {
	/**
	 * The marker a template body carries where its articles are rendered.
	 *
	 * @var string
	 */
	public const ARTICLES_MARKER = '{{articles}}';

	/**
	 * Rendering format for an HTML mail body.
	 *
	 * @var string
	 */
	public const FORMAT_HTML = 'html';

	/**
	 * Rendering format for a plain-text mail body.
	 *
	 * @var string
	 */
	public const FORMAT_TEXT = 'text';

	/**
	 * The read-more label per language the block can render.
	 *
	 * Not an `IL10N` lookup: the label lands in a mail body written in the
	 * article's language, not the server's. Add a language here when a
	 * tenant writes in one.
	 *
	 * @var array<string, string>
	 */
	private const READ_MORE_LABELS = [
		'en' => 'Read more',
		'nl' => 'Lees verder',
		'de' => 'Weiterlesen',
		'fr' => 'Lire la suite',
	];

	/**
	 * Default Article schema slug, matching the register fragment.
	 *
	 * @var string
	 */
	private const DEFAULT_ARTICLE_SCHEMA_SLUG = 'article';

	/**
	 * Default CampaignTemplate schema slug.
	 *
	 * @var string
	 */
	private const DEFAULT_TEMPLATE_SCHEMA_SLUG = 'campaignTemplate';

	/**
	 * Default Blast schema slug.
	 *
	 * @var string
	 */
	private const DEFAULT_BLAST_SCHEMA_SLUG = 'blast';

	/**
	 * The statuses an article may hold, mirroring the schema enum.
	 *
	 * @var array<int, string>
	 */
	private const STATUSES = ['draft', 'review', 'published', 'archived'];

	/**
	 * The transitions the schema lifecycle declares, restated so a refusal
	 * happens before a write rather than after one.
	 *
	 * OpenRegister enforces the same table; this copy exists so the service
	 * can answer a caller without a round trip, and it is checked against the
	 * fragment by the unit tests.
	 *
	 * @var array<string, array{from: array<int, string>, to: string}>
	 */
	private const TRANSITIONS = [
		'submitForReview' => ['from' => ['draft'], 'to' => 'review'],
		'returnToDraft' => ['from' => ['review'], 'to' => 'draft'],
		'publish' => ['from' => ['draft', 'review'], 'to' => 'published'],
		'archive' => ['from' => ['draft', 'review', 'published'], 'to' => 'archived'],
		'restore' => ['from' => ['archived'], 'to' => 'draft'],
	];

	/**
	 * Fields a client may set on a create or a patch. Anything else in the
	 * request body is dropped rather than merged, so `author`, `publishedAt`
	 * and the agent mark can only ever be set by this service.
	 *
	 * @var array<int, string>
	 */
	private const WRITABLE_FIELDS = [
		'title',
		'slug',
		'summary',
		'body',
		'heroImage',
		'language',
		'portalPageRef',
	];

	/**
	 * Constructor.
	 *
	 * @param ListObjectStore $store Register-scoped object access.
	 *
	 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
	 */
	public function __construct(
		private ListObjectStore $store,
	) {
	}//end __construct()

	/**
	 * List articles with a pagination envelope, newest first.
	 *
	 * @param int $page 1-based page number (clamped to >= 1).
	 * @param int $limit Page size (clamped 1..100).
	 *
	 * @return array{data: array<int, array<string, mixed>>, pagination: array{page: int, limit: int, total: int, pages: int}}
	 *
	 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
	 */
	public function listArticles(int $page, int $limit): array {
		$page = max(1, $page);
		$limit = min(100, max(1, $limit));
		$all = $this->store->findAll(schemaSlug: $this->articleSchemaSlug());
		usort(
			$all,
			static fn (array $left, array $right): int => strcmp(
				(string)($right['createdAt'] ?? ''),
				(string)($left['createdAt'] ?? ''),
			)
		);
		$total = count($all);

		return [
			'data' => array_slice($all, (($page - 1) * $limit), $limit),
			'pagination' => [
				'page' => $page,
				'limit' => $limit,
				'total' => $total,
				'pages' => (int)max(1, (int)ceil(($total / $limit))),
			],
		];
	}//end listArticles()

	/**
	 * Fetch one article by UUID or slug.
	 *
	 * @param string $articleId Article UUID or slug.
	 *
	 * @return array<string, mixed>|null The payload, or null when it does not exist.
	 *
	 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
	 */
	public function getArticleById(string $articleId): ?array {
		return $this->store->find(schemaSlug: $this->articleSchemaSlug(), id: $articleId);
	}//end getArticleById()

	/**
	 * Create an article as a draft.
	 *
	 * The author is stamped from the authenticated user, the slug is derived
	 * from the title when none was given, and `agentAuthored` /
	 * `agentAuthoredBy` are set from the writer's own identity rather than
	 * from the body: a mark a client can set is not a mark (ADR-088).
	 *
	 * @param array<string, mixed> $payload Inbound payload.
	 * @param string $authorUid Authenticated user id.
	 * @param string $agent Agent identity when an agent is writing, empty for a person.
	 *
	 * @return array{article?: array<string, mixed>, error?: string}
	 *
	 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
	 */
	public function createArticle(array $payload, string $authorUid, string $agent = ''): array {
		$clean = $this->sanitise(payload: $payload);
		$title = trim((string)($clean['title'] ?? ''));
		if ($title === '') {
			return ['error' => 'A title is required'];
		}

		$slug = $this->resolveSlug(candidate: (string)($clean['slug'] ?? ''), title: $title);
		if ($this->slugTaken(slug: $slug, exceptId: '') === true) {
			return ['error' => 'That slug is already in use'];
		}

		$now = $this->nowIso();
		$record = array_merge(
			$clean,
			[
				'title' => $title,
				'slug' => $slug,
				'status' => 'draft',
				'author' => $authorUid,
				'agentAuthored' => ($agent !== ''),
				'agentAuthoredBy' => $agent,
				'createdAt' => $now,
				'updatedAt' => $now,
			]
		);

		$saved = $this->store->save(schemaSlug: $this->articleSchemaSlug(), payload: $record);
		if ($saved === null) {
			return ['error' => 'The article could not be saved'];
		}

		return ['article' => $saved];
	}//end createArticle()

	/**
	 * Patch an article's editable fields.
	 *
	 * @param string $articleId Article UUID or slug.
	 * @param array<string, mixed> $patch Fields to change.
	 * @param string $agent Agent identity when an agent is writing, empty for a person.
	 *
	 * @return array{article?: array<string, mixed>, error?: string}
	 *
	 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
	 */
	public function updateArticle(string $articleId, array $patch, string $agent = ''): array {
		$existing = $this->getArticleById(articleId: $articleId);
		if ($existing === null) {
			return ['error' => 'Not found'];
		}

		$clean = $this->sanitise(payload: $patch);
		if (isset($clean['title']) === true && trim((string)$clean['title']) === '') {
			return ['error' => 'A title is required'];
		}

		if (isset($clean['slug']) === true) {
			$slug = $this->resolveSlug(
				candidate: (string)$clean['slug'],
				title: (string)($clean['title'] ?? ($existing['title'] ?? '')),
			);
			if ($this->slugTaken(slug: $slug, exceptId: $this->store->idOf(payload: $existing)) === true) {
				return ['error' => 'That slug is already in use'];
			}

			$clean['slug'] = $slug;
		}

		$merged = array_merge($existing, $clean);
		$merged['updatedAt'] = $this->nowIso();
		$merged['agentAuthored'] = ($agent !== '');
		$merged['agentAuthoredBy'] = $agent;

		$saved = $this->store->save(
			schemaSlug: $this->articleSchemaSlug(),
			payload: $merged,
			id: $this->store->idOf(payload: $existing),
		);
		if ($saved === null) {
			return ['error' => 'The article could not be saved'];
		}

		return ['article' => $saved];
	}//end updateArticle()

	/**
	 * Publish an article, stamping `publishedAt` on the first publish only.
	 *
	 * @param string $articleId Article UUID or slug.
	 *
	 * @return array{article?: array<string, mixed>, error?: string}
	 *
	 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-moves-through-a-declared-lifecycle
	 */
	public function publishArticle(string $articleId): array {
		return $this->applyTransition(articleId: $articleId, transition: 'publish');
	}//end publishArticle()

	/**
	 * Archive an article, leaving it readable and every reference intact.
	 *
	 * @param string $articleId Article UUID or slug.
	 *
	 * @return array{article?: array<string, mixed>, error?: string}
	 *
	 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-moves-through-a-declared-lifecycle
	 */
	public function archiveArticle(string $articleId): array {
		return $this->applyTransition(articleId: $articleId, transition: 'archive');
	}//end archiveArticle()

	/**
	 * Apply one declared transition to an article.
	 *
	 * A transition the lifecycle does not declare from the article's current
	 * status is refused before anything is written, so an archived article
	 * cannot be published straight back into a mailing.
	 *
	 * @param string $articleId Article UUID or slug.
	 * @param string $transition Transition name from the declared lifecycle.
	 *
	 * @return array{article?: array<string, mixed>, error?: string}
	 *
	 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-moves-through-a-declared-lifecycle
	 */
	public function applyTransition(string $articleId, string $transition): array {
		$rule = (self::TRANSITIONS[$transition] ?? null);
		if ($rule === null) {
			return ['error' => 'Unknown transition'];
		}

		$existing = $this->getArticleById(articleId: $articleId);
		if ($existing === null) {
			return ['error' => 'Not found'];
		}

		$status = (string)($existing['status'] ?? 'draft');
		if (in_array($status, self::STATUSES, true) === false) {
			$status = 'draft';
		}

		if (in_array($status, $rule['from'], true) === false) {
			return ['error' => 'That change is not allowed from the current status'];
		}

		$merged = $existing;
		$merged['status'] = $rule['to'];
		$merged['updatedAt'] = $this->nowIso();
		if ($rule['to'] === 'published' && trim((string)($existing['publishedAt'] ?? '')) === '') {
			$merged['publishedAt'] = $merged['updatedAt'];
		}

		$saved = $this->store->save(
			schemaSlug: $this->articleSchemaSlug(),
			payload: $merged,
			id: $this->store->idOf(payload: $existing),
		);
		if ($saved === null) {
			return ['error' => 'The article could not be saved'];
		}

		return ['article' => $saved];
	}//end applyTransition()

	/**
	 * Where an article has been used, derived at read time.
	 *
	 * Nothing is written. The answer is the campaign templates whose
	 * `articleIds` name the article, plus the blasts built on those templates,
	 * so removing the article from a template removes the usage on the next
	 * read rather than on the next write to the article.
	 *
	 * @param string $articleId Article UUID or slug.
	 *
	 * @return array{data: array<int, array{kind: string, id: string, name: string, status: string}>, counts: array<string, int>}
	 *
	 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-reports-where-it-has-been-used
	 */
	public function listUsages(string $articleId): array {
		$article = $this->getArticleById(articleId: $articleId);
		if ($article === null) {
			return ['data' => [], 'counts' => ['template' => 0, 'blast' => 0]];
		}

		$identities = $this->identitiesOf(object: $article);
		$usages = [];
		$templateIds = [];
		foreach ($this->store->findAll(schemaSlug: $this->templateSchemaSlug()) as $template) {
			if ($this->namesArticle(template: $template, identities: $identities) === false) {
				continue;
			}

			$templateId = $this->store->idOf(payload: $template);
			$templateIds[] = $templateId;
			foreach ($this->identitiesOf(object: $template) as $identity) {
				$templateIds[] = $identity;
			}

			$usages[] = [
				'kind' => 'template',
				'id' => $templateId,
				'name' => (string)($template['name'] ?? ''),
				'status' => (string)($template['channel'] ?? ''),
			];
		}

		foreach ($this->store->findAll(schemaSlug: $this->blastSchemaSlug()) as $blast) {
			if (in_array((string)($blast['templateId'] ?? ''), $templateIds, true) === false) {
				continue;
			}

			$usages[] = [
				'kind' => 'blast',
				'id' => $this->store->idOf(payload: $blast),
				'name' => (string)($blast['name'] ?? ''),
				'status' => (string)($blast['status'] ?? ''),
			];
		}

		return [
			'data' => $usages,
			'counts' => [
				'template' => count(array_filter($usages, static fn (array $u): bool => $u['kind'] === 'template')),
				'blast' => count(array_filter($usages, static fn (array $u): bool => $u['kind'] === 'blast')),
			],
		];
	}//end listUsages()

	/**
	 * Load the articles a template names, in the order it named them.
	 *
	 * An id that resolves to nothing is skipped rather than rendered as a
	 * gap: a deleted article should shorten the newsletter, not break it.
	 *
	 * @param array<int, mixed> $articleIds Article UUIDs or slugs.
	 *
	 * @return array<int, array<string, mixed>> The resolved articles.
	 *
	 * @spec openspec/changes/marketing-article-hub/specs/marketing-blast/spec.md#requirement-a-campaign-template-may-embed-articles
	 */
	public function loadArticlesByIds(array $articleIds): array {
		$out = [];
		foreach ($articleIds as $id) {
			if (is_scalar($id) === false || (string)$id === '') {
				continue;
			}

			$article = $this->getArticleById(articleId: (string)$id);
			if ($article !== null) {
				$out[] = $article;
			}
		}

		return $out;
	}//end loadArticlesByIds()

	/**
	 * Replace the `{{articles}}` marker in a body with the rendered articles.
	 *
	 * A body carrying no marker is returned byte-identical, so a template
	 * written before this change renders exactly as it did.
	 *
	 * @param string $body The template body.
	 * @param array<int, array<string, mixed>> $articles Already-loaded articles.
	 * @param string $format {@see self::FORMAT_HTML} or {@see self::FORMAT_TEXT}.
	 *
	 * @return string The body with the marker expanded.
	 *
	 * @spec openspec/changes/marketing-article-hub/specs/marketing-blast/spec.md#requirement-a-campaign-template-may-embed-articles
	 */
	public function expandArticlesMarker(string $body, array $articles, string $format): string {
		if (str_contains($body, self::ARTICLES_MARKER) === false) {
			return $body;
		}

		return str_replace(
			self::ARTICLES_MARKER,
			$this->renderArticlesBlock(articles: $articles, format: $format),
			$body,
		);
	}//end expandArticlesMarker()

	/**
	 * Render articles as an HTML or plain-text block.
	 *
	 * Each article renders its title, its summary, its hero image and a
	 * read-more link. The link is rendered only when `portalPageRef` is set,
	 * because a read-more that goes nowhere is worse than none. The hero is
	 * rendered only when it is an absolute http(s) URL: a path into Nextcloud
	 * Files is not reachable from a mail client, and an `<img>` pointing at
	 * one is a broken image in every inbox.
	 *
	 * @param array<int, array<string, mixed>> $articles Already-loaded articles.
	 * @param string $format {@see self::FORMAT_HTML} or {@see self::FORMAT_TEXT}.
	 *
	 * @return string The rendered block, empty when there are no articles.
	 *
	 * @spec openspec/changes/marketing-article-hub/specs/marketing-blast/spec.md#requirement-a-campaign-template-may-embed-articles
	 */
	public function renderArticlesBlock(array $articles, string $format): string {
		$parts = [];
		foreach ($articles as $article) {
			if (is_array($article) === false) {
				continue;
			}

			if ($format === self::FORMAT_TEXT) {
				$parts[] = $this->renderArticleText(article: $article);
				continue;
			}

			$parts[] = $this->renderArticleHtml(article: $article);
		}

		if ($parts === []) {
			return '';
		}

		if ($format === self::FORMAT_TEXT) {
			return implode("\n\n", $parts);
		}

		return '<div class="pipelinq-articles">' . implode('', $parts) . '</div>';
	}//end renderArticlesBlock()

	/**
	 * Render one article as HTML, escaping every value it carries.
	 *
	 * @param array<string, mixed> $article The article.
	 *
	 * @return string One article's HTML.
	 */
	private function renderArticleHtml(array $article): string {
		$title = $this->escape(value: (string)($article['title'] ?? ''));
		$summary = $this->escape(value: (string)($article['summary'] ?? ''));
		$html = '<div class="pipelinq-article">';

		$hero = $this->mailableHero(article: $article);
		if ($hero !== '') {
			$html .= '<img src="' . $this->escape(value: $hero) . '" alt="' . $title . '" />';
		}

		$html .= '<h2>' . $title . '</h2>';
		if ($summary !== '') {
			$html .= '<p>' . $summary . '</p>';
		}

		$link = trim((string)($article['portalPageRef'] ?? ''));
		if ($link !== '') {
			$label = $this->escape(value: $this->readMoreLabel(article: $article));
			$html .= '<p><a href="' . $this->escape(value: $link) . '">' . $label . '</a></p>';
		}

		return ($html . '</div>');
	}//end renderArticleHtml()

	/**
	 * Render one article as plain text.
	 *
	 * @param array<string, mixed> $article The article.
	 *
	 * @return string One article's plain text.
	 */
	private function renderArticleText(array $article): string {
		$lines = [trim((string)($article['title'] ?? ''))];
		$summary = trim((string)($article['summary'] ?? ''));
		if ($summary !== '') {
			$lines[] = $summary;
		}

		$link = trim((string)($article['portalPageRef'] ?? ''));
		if ($link !== '') {
			$lines[] = ($this->readMoreLabel(article: $article) . ': ' . $link);
		}

		return implode("\n", $lines);
	}//end renderArticleText()

	/**
	 * The read-more label in the article's own language.
	 *
	 * The label goes into a mail body, so it cannot come from the server's
	 * locale or from `IL10N`: a Dutch newsletter sent from an English instance
	 * would carry an English link. It follows the article's `language` field,
	 * which is the language the body was written in, and falls back to English
	 * for a language nothing here speaks.
	 *
	 * @param array<string, mixed> $article The article.
	 *
	 * @return string The label.
	 */
	private function readMoreLabel(array $article): string {
		$language = strtolower(substr(trim((string)($article['language'] ?? '')), 0, 2));

		return (self::READ_MORE_LABELS[$language] ?? self::READ_MORE_LABELS['en']);
	}//end readMoreLabel()

	/**
	 * The hero image when it is reachable from a mail client, else empty.
	 *
	 * @param array<string, mixed> $article The article.
	 *
	 * @return string An absolute http(s) URL, or an empty string.
	 */
	private function mailableHero(array $article): string {
		$hero = trim((string)($article['heroImage'] ?? ''));
		if (str_starts_with($hero, 'https://') === true || str_starts_with($hero, 'http://') === true) {
			return $hero;
		}

		return '';
	}//end mailableHero()

	/**
	 * HTML-escape a value for a mail body.
	 *
	 * @param string $value The raw value.
	 *
	 * @return string The escaped value.
	 */
	private function escape(string $value): string {
		return htmlspecialchars($value, (ENT_QUOTES | ENT_SUBSTITUTE), 'UTF-8');
	}//end escape()

	/**
	 * Whether a template's `articleIds` name one of an article's identities.
	 *
	 * @param array<string, mixed> $template The campaign template.
	 * @param array<int, string> $identities The article's ids and slugs.
	 *
	 * @return bool True when the template names the article.
	 */
	private function namesArticle(array $template, array $identities): bool {
		$ids = ($template['articleIds'] ?? []);
		if (is_array($ids) === false) {
			return false;
		}

		foreach ($ids as $id) {
			if (is_scalar($id) === true && in_array((string)$id, $identities, true) === true) {
				return true;
			}
		}

		return false;
	}//end namesArticle()

	/**
	 * Every identifier an object answers to: its id, its uuid and its slug,
	 * at the top level and under `@self`.
	 *
	 * A template names an article by whichever of these the marketer's client
	 * had to hand, and the seeds name it by slug, so a match on one alone
	 * would report an article as unused while it is embedded in a newsletter.
	 *
	 * @param array<string, mixed> $object The object.
	 *
	 * @return array<int, string> Non-empty identifiers.
	 */
	private function identitiesOf(array $object): array {
		$out = [];
		$self = ($object['@self'] ?? []);
		foreach (['uuid', 'id', 'slug'] as $key) {
			foreach ([$object, (is_array($self) === true ? $self : [])] as $source) {
				$value = ($source[$key] ?? null);
				if (is_scalar($value) === true && (string)$value !== '') {
					$out[] = (string)$value;
				}
			}
		}

		return array_values(array_unique($out));
	}//end identitiesOf()

	/**
	 * Keep only the fields a client may set.
	 *
	 * @param array<string, mixed> $payload Inbound payload.
	 *
	 * @return array<string, mixed> The writable subset, trimmed.
	 */
	private function sanitise(array $payload): array {
		$clean = [];
		foreach (self::WRITABLE_FIELDS as $key) {
			if (array_key_exists($key, $payload) === false) {
				continue;
			}

			$clean[$key] = trim((string)$payload[$key]);
		}

		foreach (['links', 'tags'] as $key) {
			if (isset($payload[$key]) === true && is_array($payload[$key]) === true) {
				$clean[$key] = $payload[$key];
			}
		}

		return $clean;
	}//end sanitise()

	/**
	 * The slug to store: the candidate when it is usable, else one derived
	 * from the title.
	 *
	 * @param string $candidate The client's slug, possibly empty.
	 * @param string $title The article title.
	 *
	 * @return string A URL-safe slug.
	 */
	private function resolveSlug(string $candidate, string $title): string {
		$slug = $this->slugify(value: $candidate);
		if ($slug !== '') {
			return $slug;
		}

		$slug = $this->slugify(value: $title);
		if ($slug !== '') {
			return $slug;
		}

		return ('article-' . substr(sha1($title . microtime(true)), 0, 8));
	}//end resolveSlug()

	/**
	 * Reduce a string to the schema's slug pattern.
	 *
	 * Written here rather than reached for across the app boundary: pulling
	 * one eight-line helper out of OpenRegister would couple pipelinq to a
	 * class it has no other reason to know (ADR-011 weighs reuse against the
	 * coupling it buys, and this trade goes the other way).
	 *
	 * @param string $value The raw value.
	 *
	 * @return string A URL-safe slug, empty when nothing survives.
	 */
	private function slugify(string $value): string {
		$ascii = iconv('UTF-8', 'ASCII//TRANSLIT', $value);
		if ($ascii === false) {
			$ascii = $value;
		}

		$lower = strtolower($ascii);
		$dashed = preg_replace('/[^a-z0-9]+/', '-', $lower);
		if (is_string($dashed) === false) {
			return '';
		}

		return trim($dashed, '-');
	}//end slugify()

	/**
	 * Whether another article already holds a slug.
	 *
	 * OpenRegister does not constrain a property to be unique, so the check
	 * runs here. Two simultaneous creates could still collide; the result is
	 * a duplicate slug visible on the index, not a lost article.
	 *
	 * @param string $slug The slug to claim.
	 * @param string $exceptId The article allowed to already hold it.
	 *
	 * @return bool True when the slug is taken.
	 */
	private function slugTaken(string $slug, string $exceptId): bool {
		foreach ($this->store->findAll(schemaSlug: $this->articleSchemaSlug()) as $row) {
			if ((string)($row['slug'] ?? '') !== $slug) {
				continue;
			}

			if ($exceptId !== '' && $this->store->idOf(payload: $row) === $exceptId) {
				continue;
			}

			return true;
		}

		return false;
	}//end slugTaken()

	/**
	 * The Article schema slug.
	 *
	 * @return string Schema slug.
	 */
	private function articleSchemaSlug(): string {
		return $this->store->schemaSlug(
			configKey: 'article_schema',
			default: self::DEFAULT_ARTICLE_SCHEMA_SLUG,
		);
	}//end articleSchemaSlug()

	/**
	 * The CampaignTemplate schema slug.
	 *
	 * @return string Schema slug.
	 */
	private function templateSchemaSlug(): string {
		return $this->store->schemaSlug(
			configKey: 'campaignTemplate_schema',
			default: self::DEFAULT_TEMPLATE_SCHEMA_SLUG,
		);
	}//end templateSchemaSlug()

	/**
	 * The Blast schema slug.
	 *
	 * @return string Schema slug.
	 */
	private function blastSchemaSlug(): string {
		return $this->store->schemaSlug(
			configKey: 'blast_schema',
			default: self::DEFAULT_BLAST_SCHEMA_SLUG,
		);
	}//end blastSchemaSlug()

	/**
	 * The current moment as an ISO-8601 UTC string.
	 *
	 * @return string Timestamp.
	 */
	private function nowIso(): string {
		return gmdate('c');
	}//end nowIso()
}//end class
