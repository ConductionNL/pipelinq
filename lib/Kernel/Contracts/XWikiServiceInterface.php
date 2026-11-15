<?php

/**
 * Contract of XWikiService
 *
 * The public surface of XWikiService that other components call, so they depend
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
 * Contract of XWikiService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface XWikiServiceInterface {

	/**
	 * Fetch rendered HTML for a single page.
	 *
	 * @param string $wiki xWiki wiki id (typically "xwiki").
	 * @param string $page Dotted page reference, e.g. "Kennisbank.Paspoort.WebHome".
	 *
	 * @return array<string, mixed> {title, content, url, modified, space, id}
	 *
	 * @spec openspec/changes/archive/2026-06-14-xwiki-integration/tasks.md#1.1
	 */
	public function getPageContent(string $wiki, string $page): array;

	/**
	 * List pages in a space.
	 *
	 * @param string $space xWiki space name (e.g. "Kennisbank").
	 * @param int $limit Max results.
	 * @param int $offset Offset.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-06-14-xwiki-integration/tasks.md#1.1
	 */
	public function getPages(string $space, int $limit = 10, int $offset = 0): array;

	/**
	 * Check xWiki status / availability.
	 *
	 * @return array<string, mixed> {available: bool, version: string, baseUrl: string, source: string}
	 *
	 * @spec openspec/changes/archive/2026-06-14-xwiki-integration/tasks.md#1.1
	 */
	public function getStatus(): array;

	/**
	 * Sanitise an HTML fragment: strip script/style tags and on* event handlers
	 * and javascript: URLs. Conservative deny-list — xWiki content is treated
	 * as semi-trusted.
	 *
	 * @param string $html Fragment.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-06-14-xwiki-integration/tasks.md#1.1
	 */
	public function sanitiseHtml(string $html): string;

	/**
	 * Search xWiki pages.
	 *
	 * @param string $query Full-text query, may be empty.
	 * @param array<string, mixed> $filter Optional `space` (string|null), `tags` (list of tags to filter
	 *                                     on; a best-effort client-side filter, xWiki REST search has no
	 *                                     generic tag filter), `limit` (1..100, default 10) and `offset`
	 *                                     (>=0, default 0).
	 *
	 * @return array<string, mixed> {results: array, total: int, limit: int, offset: int}
	 *
	 * @spec openspec/specs/xwiki-proxy/spec.md
	 */
	public function search(string $query, array $filter = []): array;
}//end interface
