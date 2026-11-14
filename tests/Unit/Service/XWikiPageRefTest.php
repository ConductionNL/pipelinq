<?php

/**
 * Unit tests for XWikiPageRef.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\XWikiPageRef;
use PHPUnit\Framework\TestCase;

/**
 * XWikiPageRef turns a dotted XWiki page reference into its REST and view paths.
 */
class XWikiPageRefTest extends TestCase {

	/**
	 * A nested page names every space and the page last.
	 *
	 * @return void
	 */
	public function testANestedPage(): void {
		$ref = new XWikiPageRef(wiki: 'xwiki', page: 'Sales.Playbooks.Onboarding');

		self::assertSame('/rest/wikis/xwiki/spaces/Sales/spaces/Playbooks/pages/Onboarding?media=xml', $ref->restPath());
		self::assertSame('/bin/view/Sales/Playbooks/Onboarding', $ref->viewPath());
		self::assertSame('Sales', $ref->space());
	}//end testANestedPage()

	/**
	 * Every segment and the wiki name are URL-encoded.
	 *
	 * @return void
	 */
	public function testSegmentsAreEncoded(): void {
		$ref = new XWikiPageRef(wiki: 'team wiki', page: 'Klant Service.Veel gestelde vragen');

		self::assertSame('/rest/wikis/team%20wiki/spaces/Klant%20Service/pages/Veel%20gestelde%20vragen?media=xml', $ref->restPath());
		self::assertSame('/bin/view/Klant%20Service/Veel%20gestelde%20vragen', $ref->viewPath());
		self::assertSame('Klant%20Service', $ref->space());
	}//end testSegmentsAreEncoded()

	/**
	 * A page without a space has an empty spaces path and is its own first segment.
	 *
	 * @return void
	 */
	public function testAPageWithoutASpace(): void {
		$ref = new XWikiPageRef(wiki: 'xwiki', page: 'Home');

		self::assertSame('/rest/wikis/xwiki/spaces//pages/Home?media=xml', $ref->restPath());
		self::assertSame('/bin/view/Home', $ref->viewPath());
		self::assertSame('Home', $ref->space());
	}//end testAPageWithoutASpace()
}//end class
