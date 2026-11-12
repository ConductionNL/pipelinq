<?php

/**
 * Pipelinq XWikiPageRef.
 *
 * A dotted xWiki page reference and the REST and view paths it maps to.
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
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/archive/2026-06-14-xwiki-integration/tasks.md#1.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * One xWiki page: its wiki id and its dotted reference ("Kennisbank.Paspoort.WebHome").
 *
 * @spec openspec/changes/archive/2026-06-14-xwiki-integration/tasks.md#1.1
 */
final class XWikiPageRef {
	/**
	 * Constructor.
	 *
	 * @param string $wiki The wiki id (typically "xwiki").
	 * @param string $page The dotted page reference.
	 */
	public function __construct(
		public readonly string $wiki,
		public readonly string $page,
	) {
	}//end __construct()

	/**
	 * The REST path of the page record: nested spaces, then the page name.
	 *
	 * @return string The path, starting with /rest.
	 *
	 * @spec openspec/changes/archive/2026-06-14-xwiki-integration/tasks.md#1.1
	 */
	public function restPath(): string {
		$segments = $this->segments();
		$pageName = (string)end($segments);
		$spacesPath = implode('/spaces/', array_slice($segments, 0, -1));
		return sprintf('/rest/wikis/%s/spaces/%s/pages/%s?media=xml', rawurlencode($this->wiki), $spacesPath, $pageName);
	}//end restPath()

	/**
	 * The path of the rendered page.
	 *
	 * @return string The path, starting with /bin/view.
	 *
	 * @spec openspec/changes/archive/2026-06-14-xwiki-integration/tasks.md#1.1
	 */
	public function viewPath(): string {
		return sprintf('/bin/view/%s', implode('/', $this->segments()));
	}//end viewPath()

	/**
	 * The top-level space of the page (URL-encoded).
	 *
	 * @return string The space.
	 *
	 * @spec openspec/changes/archive/2026-06-14-xwiki-integration/tasks.md#1.1
	 */
	public function space(): string {
		return (string)($this->segments()[0] ?? '');
	}//end space()

	/**
	 * The URL-encoded segments of the dotted reference.
	 *
	 * @return array<int, string> The segments.
	 */
	private function segments(): array {
		return array_map('rawurlencode', explode('.', $this->page));
	}//end segments()
}//end class
