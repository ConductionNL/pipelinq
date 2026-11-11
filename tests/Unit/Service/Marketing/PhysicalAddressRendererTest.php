<?php

/**
 * Unit tests for PhysicalAddressRenderer.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/marketing-compliance/spec.md#requirement-unsubscribe-footer-enforced-on-email-templates
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\Marketing\PhysicalAddressRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PhysicalAddressRenderer — where a template's physical address
 * lands in an email body.
 *
 * @spec openspec/specs/marketing-compliance/spec.md#requirement-unsubscribe-footer-enforced-on-email-templates
 */
class PhysicalAddressRendererTest extends TestCase {
	/**
	 * Every address token takes the footerOverride, HTML-escaped with its
	 * line breaks kept.
	 *
	 * @return void
	 */
	public function testRenderReplacesTokens(): void {
		$body = '<p>{{physical_address}}</p><p>{{company_address}}</p>';
		$this->assertSame(
			'<p>A &amp; B<br>' . "\n" . 'Den Haag</p><p>A &amp; B<br>' . "\n" . 'Den Haag</p>',
			(new PhysicalAddressRenderer())->render($body, "A & B\nDen Haag", 'html'),
		);
	}//end testRenderReplacesTokens()

	/**
	 * A body without a token gets the address appended; an empty body and an
	 * empty address leave the body alone.
	 *
	 * @return void
	 */
	public function testRenderAppendsOrLeavesAlone(): void {
		$renderer = new PhysicalAddressRenderer();
		$this->assertSame('<p>Hi</p><p>Den Haag</p>', $renderer->render('<p>Hi</p>', 'Den Haag', 'html'));
		$this->assertSame(
			'<html><body><p>Hi</p><p>Den Haag</p></body></html>',
			$renderer->render('<html><body><p>Hi</p></body></html>', 'Den Haag', 'html'),
		);
		$this->assertSame("Hi\n\nDen Haag", $renderer->render("Hi\n", 'Den Haag', 'text'));
		$this->assertSame('', $renderer->render('', 'Den Haag', 'text'));
		$this->assertSame('<p>{{physical_address}}</p>', $renderer->render('<p>{{physical_address}}</p>', '  ', 'html'));
	}//end testRenderAppendsOrLeavesAlone()
	/**
	 * Every render, exactly: a blank address or body leaves the body; HTML
	 * escapes the address and breaks its lines; any one token makes every
	 * token the address; an HTML body without a token gets a paragraph
	 * inside its last body element, or at the end; plain text gets the
	 * address after a blank line, trailing space trimmed.
	 *
	 * @return void
	 */
	public function testEveryRender(): void {
		$renderer = new PhysicalAddressRenderer();
		$this->assertSame('Hoi', $renderer->render(body: 'Hoi', footerOverride: ' ', format: 'html'));
		$this->assertSame(' ', $renderer->render(body: ' ', footerOverride: 'Straat 1', format: 'text'));
		$this->assertSame(
			'<p>A&amp;B<br>1234 AB Ede</p><p>A&amp;B<br>1234 AB Ede</p>',
			$renderer->render(body: '<p>{{address_block}}</p><p>{{sender_address}}</p>', footerOverride: " A&B\n1234 AB Ede ", format: 'html')
		);
		$this->assertSame('<html><BODY>x<p>Straat 1</p></BODY></html>', $renderer->render(body: '<html><BODY>x</BODY></html>', footerOverride: 'Straat 1', format: 'html'));
		$this->assertSame('<b>x</b><p>Straat 1</p>', $renderer->render(body: '<b>x</b>', footerOverride: 'Straat 1', format: 'html'));
		$this->assertSame("Hoi\n\nStraat & 1", $renderer->render(body: "Hoi  \n", footerOverride: 'Straat & 1', format: 'text'));
		$this->assertSame('Aan: Straat 1', $renderer->render(body: 'Aan: {{physical_address}}', footerOverride: 'Straat 1', format: 'text'));
	}//end testEveryRender()
}//end class
