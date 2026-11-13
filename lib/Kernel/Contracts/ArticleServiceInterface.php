<?php

/**
 * Contract of ArticleService
 *
 * The public surface of ArticleService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

/**
 * Contract of ArticleService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ArticleServiceInterface {

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
	 * @spec openspec/specs/marketing-articles/spec.md#requirement-an-article-moves-through-a-declared-lifecycle
	 */
	public function applyTransition(string $articleId, string $transition): array;

	/**
	 * Archive an article, leaving it readable and every reference intact.
	 *
	 * @param string $articleId Article UUID or slug.
	 *
	 * @return array{article?: array<string, mixed>, error?: string}
	 *
	 * @spec openspec/specs/marketing-articles/spec.md#requirement-an-article-moves-through-a-declared-lifecycle
	 */
	public function archiveArticle(string $articleId): array;

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
	 * @spec openspec/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
	 */
	public function createArticle(array $payload, string $authorUid, string $agent = ''): array;

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
	public function expandArticlesMarker(string $body, array $articles, string $format): string;

	/**
	 * Fetch one article by UUID or slug.
	 *
	 * @param string $articleId Article UUID or slug.
	 *
	 * @return array<string, mixed>|null The payload, or null when it does not exist.
	 *
	 * @spec openspec/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
	 */
	public function getArticleById(string $articleId): ?array;

	/**
	 * List articles with a pagination envelope, newest first.
	 *
	 * @param int $page 1-based page number (clamped to >= 1).
	 * @param int $limit Page size (clamped 1..100).
	 *
	 * @return array{data: array<int, array<string, mixed>>, pagination: array{page: int, limit: int, total: int, pages: int}}
	 *
	 * @spec openspec/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
	 */
	public function listArticles(int $page, int $limit): array;

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
	 * @spec openspec/specs/marketing-articles/spec.md#requirement-an-article-reports-where-it-has-been-used
	 */
	public function listUsages(string $articleId): array;

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
	 * @spec openspec/specs/marketing-blast/spec.md#requirement-a-campaign-template-may-embed-articles
	 */
	public function loadArticlesByIds(array $articleIds): array;

	/**
	 * Publish an article, stamping `publishedAt` on the first publish only.
	 *
	 * @param string $articleId Article UUID or slug.
	 *
	 * @return array{article?: array<string, mixed>, error?: string}
	 *
	 * @spec openspec/specs/marketing-articles/spec.md#requirement-an-article-moves-through-a-declared-lifecycle
	 */
	public function publishArticle(string $articleId): array;

	/**
	 * Patch an article's editable fields.
	 *
	 * @param string $articleId Article UUID or slug.
	 * @param array<string, mixed> $patch Fields to change.
	 * @param string $agent Agent identity when an agent is writing, empty for a person.
	 *
	 * @return array{article?: array<string, mixed>, error?: string}
	 *
	 * @spec openspec/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
	 */
	public function updateArticle(string $articleId, array $patch, string $agent = ''): array;
}//end interface
