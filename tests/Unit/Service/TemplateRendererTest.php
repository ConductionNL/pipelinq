<?php

/**
 * Unit tests for TemplateRenderer — Mustache variable substitution,
 * XHTML strict validation, subject truncation, deep-link construction.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-template-013
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-deeplink-014
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\TemplateRenderer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for TemplateRenderer.
 */
class TemplateRendererTest extends TestCase {
	/**
	 * Build the renderer.
	 *
	 * @return TemplateRenderer
	 */
	private function buildRenderer(): TemplateRenderer {
		return new TemplateRenderer($this->createMock(LoggerInterface::class));
	}//end buildRenderer()

	/**
	 * Variables are substituted in subject + body.
	 *
	 * @return void
	 */
	public function testBasicVariableSubstitution(): void {
		$renderer = $this->buildRenderer();
		$tpl = [
			'subject' => 'Uw paspoort is gereed - zaak {{zaakId}}',
			'body' => '<p>Status: <strong>{{status}}</strong> bij {{gemeente}}.</p>',
		];
		$vars = [
			'zaakId' => 'Z-2026-0042',
			'status' => 'afgehandeld',
			'gemeente' => 'Amsterdam',
		];

		$rendered = $renderer->render($tpl, $vars);
		$this->assertSame('Uw paspoort is gereed - zaak Z-2026-0042', $rendered['subject']);
		$this->assertStringContainsString('afgehandeld', $rendered['body']);
		$this->assertStringContainsString('Amsterdam', $rendered['body']);
	}//end testBasicVariableSubstitution()

	/**
	 * Subject is truncated to 200 chars per BBK 1.7.
	 *
	 * @return void
	 */
	public function testSubjectTruncation(): void {
		$renderer = $this->buildRenderer();
		$tpl = [
			'subject' => str_repeat('A', 250),
			'body' => '<p>x</p>',
		];
		$rendered = $renderer->render($tpl, []);
		$this->assertSame(200, mb_strlen($rendered['subject']));
	}//end testSubjectTruncation()

	/**
	 * Missing variables substitute as empty string.
	 *
	 * @return void
	 */
	public function testMissingVariablesAreEmpty(): void {
		$renderer = $this->buildRenderer();
		$tpl = [
			'subject' => 'X-{{missing}}-Y',
			'body' => '<p>{{missing}}</p>',
		];
		$rendered = $renderer->render($tpl, []);
		$this->assertSame('X--Y', $rendered['subject']);
	}//end testMissingVariablesAreEmpty()

	/**
	 * Double-brace substitution HTML-escapes; triple-brace preserves raw.
	 *
	 * @return void
	 */
	public function testEscapeRules(): void {
		$renderer = $this->buildRenderer();
		$tpl = [
			'subject' => 'safe',
			'body' => '<p>{{x}}</p><p>{{{x}}}</p>',
		];
		$rendered = $renderer->render($tpl, ['x' => '<b>bold</b>']);
		$this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $rendered['body']);
		$this->assertStringContainsString('<p><b>bold</b></p>', $rendered['body']);
	}//end testEscapeRules()

	/**
	 * Invalid XHTML body throws.
	 *
	 * @return void
	 */
	public function testInvalidXhtmlRejected(): void {
		$renderer = $this->buildRenderer();
		$tpl = [
			'subject' => 'x',
			// <br> (not <br/>) is invalid XHTML strict.
			'body' => '<p>broken <br></p>',
		];
		$this->expectException(\RuntimeException::class);
		$renderer->render($tpl, []);
	}//end testInvalidXhtmlRejected()

	/**
	 * Deep-link URL is constructed from deepLinkBase + query params.
	 *
	 * @return void
	 */
	public function testBuildDeepLink(): void {
		$renderer = $this->buildRenderer();
		$tpl = [
			'requiresDeepLink' => true,
			'deepLinkBase' => 'https://burgerportaal.gemeente.nl/zaak',
			'subject' => 'x',
			'body' => '<p>x</p>',
		];
		$vars = [
			'zaakId' => 'Z-2026-0042',
			'status' => 'afgehandeld',
			'messageId' => 'msg-uuid-1',
		];

		$rendered = $renderer->render($tpl, $vars);
		$this->assertNotNull($rendered['deepLink']);
		$this->assertStringContainsString('zaakId=Z-2026-0042', $rendered['deepLink']);
		$this->assertStringContainsString('ref=msg-uuid-1', $rendered['deepLink']);
	}//end testBuildDeepLink()

	/**
	 * No deepLinkBase → null deepLink + log warning.
	 *
	 * @return void
	 */
	public function testMissingDeepLinkBaseReturnsNull(): void {
		$renderer = $this->buildRenderer();
		$tpl = [
			'requiresDeepLink' => true,
			'deepLinkBase' => '',
			'subject' => 'x',
			'body' => '<p>x</p>',
		];
		$rendered = $renderer->render($tpl, ['zaakId' => 'Z-1']);
		$this->assertNull($rendered['deepLink']);
	}//end testMissingDeepLinkBaseReturnsNull()
}//end class
