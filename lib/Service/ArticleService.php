<?php

/**
 * Pipelinq ArticleService.
 *
 * Marketing articles live in portaliq (marketing-articles-move-to-portaliq);
 * pipelinq only names them. What stays here is reading the articles a
 * template names and rendering the `{{articles}}` block, from one method
 * that both the send path and the preview call, so a marketer's preview is
 * produced by the code that will do the sending.
 *
 * Register access goes through {@see PortaliqArticleStore}, which checks that
 * portaliq is installed before every read.
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
 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-articles/spec.md#requirement-pipelinq-reads-marketing-articles-from-portaliq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Kernel\Contracts\ArticleServiceInterface;
use OCA\Pipelinq\Service\Marketing\PortaliqArticleStore;
use Psr\Log\LoggerInterface;

/**
 * ArticleService — reads portaliq articles and renders the `{{articles}}` block.
 *
 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-articles/spec.md#requirement-pipelinq-reads-marketing-articles-from-portaliq
 */
class ArticleService implements ArticleServiceInterface {
	/**
	 * The marker a template body carries where its articles are rendered.
	 *
	 * @var string
	 */
	public const ARTICLES_MARKER = '{{articles}}';

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
	 * Constructor.
	 *
	 * @param PortaliqArticleStore $articles portaliq's articles.
	 * @param LoggerInterface      $logger   The logger.
	 *
	 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-articles/spec.md#requirement-pipelinq-reads-marketing-articles-from-portaliq
	 */
	public function __construct(
		private PortaliqArticleStore $articles,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The published portaliq articles a template or a post may pick.
	 *
	 * @return array{available: bool, data: array<int, array<string, mixed>>} `available` is false without portaliq.
	 *
	 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-ui/spec.md#requirement-the-templates-form-lets-a-marketer-pick-articles
	 */
	public function publishedArticles(): array {
		if ($this->articles->isAvailable() === false) {
			return ['available' => false, 'data' => []];
		}

		return ['available' => true, 'data' => $this->articles->published()];
	}//end publishedArticles()

	/**
	 * Load the articles a template names, in the order it named them.
	 *
	 * An id that resolves to nothing is skipped rather than rendered as a
	 * gap: a deleted article should shorten the newsletter, not break it.
	 * Without portaliq nothing resolves, the reason is logged, and the send
	 * goes ahead with an empty block.
	 *
	 * @param array<int, mixed> $articleIds portaliq article ids or slugs.
	 *
	 * @return array<int, array<string, mixed>> The resolved articles.
	 *
	 * @spec openspec/specs/marketing-blast/spec.md#requirement-a-campaign-template-may-embed-articles
	 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-articles/spec.md#requirement-pipelinq-reads-marketing-articles-from-portaliq
	 */
	public function loadArticlesByIds(array $articleIds): array {
		$ids = array_values(array_filter($articleIds, static fn (mixed $id): bool => is_scalar($id) === true && (string)$id !== ''));
		if ($ids === []) {
			return [];
		}

		if ($this->articles->isAvailable() === false) {
			$this->logger->info(
				'Pipelinq: an {{articles}} block renders empty because portaliq, which holds marketing articles, is not installed',
				['articleIds' => count($ids)]
			);
			return [];
		}

		$out = [];
		foreach ($ids as $id) {
			$article = $this->articles->find(identity: (string)$id);
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
	 * @spec openspec/specs/marketing-blast/spec.md#requirement-a-campaign-template-may-embed-articles
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
	 * @spec openspec/specs/marketing-blast/spec.md#requirement-a-campaign-template-may-embed-articles
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
}//end class
