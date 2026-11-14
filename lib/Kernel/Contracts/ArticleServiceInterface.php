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
	 * The published portaliq articles a template or a post may pick.
	 *
	 * @return array{available: bool, data: array<int, array<string, mixed>>} `available` is false without portaliq.
	 *
	 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-ui/spec.md#requirement-the-templates-form-lets-a-marketer-pick-articles
	 */
	public function publishedArticles(): array;
}//end interface
