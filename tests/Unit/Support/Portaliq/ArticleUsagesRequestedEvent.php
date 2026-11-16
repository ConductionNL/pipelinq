<?php

/**
 * A test copy of portaliq's ArticleUsagesRequestedEvent.
 *
 * pipelinq knows the event by name only; this copy carries the same public
 * surface as portaliq's class so the listener can be exercised without
 * portaliq installed.
 *
 * @category Test
 * @package  OCA\Portaliq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Event;

use OCP\EventDispatcher\Event;

/**
 * Collects the usages of one article.
 */
class ArticleUsagesRequestedEvent extends Event {

	/**
	 * The usages added so far.
	 *
	 * @var array<int, array{id: string, title: string, kind: string, app: string, url: string}>
	 */
	private array $usages = [];

	/**
	 * Constructor.
	 *
	 * @param string $articleId The article's id.
	 * @param string $slug      The article's slug.
	 * @param string $legacyRef Where the article came from.
	 */
	public function __construct(
		private readonly string $articleId,
		private readonly string $slug = '',
		private readonly string $legacyRef = '',
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The article's id.
	 *
	 * @return string
	 */
	public function getArticleId(): string {
		return $this->articleId;
	}//end getArticleId()

	/**
	 * The article's slug.
	 *
	 * @return string
	 */
	public function getSlug(): string {
		return $this->slug;
	}//end getSlug()

	/**
	 * Where the article came from.
	 *
	 * @return string
	 */
	public function getLegacyRef(): string {
		return $this->legacyRef;
	}//end getLegacyRef()

	/**
	 * Add one usage.
	 *
	 * @param string $id    The using object's id.
	 * @param string $title Its display name.
	 * @param string $kind  What it is.
	 * @param string $app   The app that owns it.
	 * @param string $url   Where it opens.
	 *
	 * @return void
	 */
	public function addUsage(string $id, string $title, string $kind, string $app, string $url = ''): void {
		$this->usages[] = ['id' => $id, 'title' => $title, 'kind' => $kind, 'app' => $app, 'url' => $url];
	}//end addUsage()

	/**
	 * The usages added.
	 *
	 * @return array<int, array{id: string, title: string, kind: string, app: string, url: string}>
	 */
	public function getUsages(): array {
		return $this->usages;
	}//end getUsages()
}//end class
