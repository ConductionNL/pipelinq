<?php
/**
 * Tests for MailBlockRenderer (marketing-block-editor).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\Marketing\MailBlockRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Blocks become one mail-safe HTML body and a plain-text twin.
 */
class MailBlockRendererTest extends TestCase {

	/**
	 * Render blocks with the default options.
	 *
	 * @param array<int, array<string, mixed>> $blocks The blocks.
	 *
	 * @return array{html: string, text: string} The output.
	 */
	private function render(array $blocks): array {
		return (new MailBlockRenderer())->render(blocks: $blocks, options: ['primaryColor' => '#123456']);
	}//end render()

	/**
	 * A script in a heading is sent as text, never as an element.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-saved-blocks-become-mail-safe-html-req-mbe-003
	 */
	public function testAScriptInAHeadingIsText(): void {
		$out = $this->render([['id' => 'h', 'type' => 'heading', 'props' => ['text' => '<script>alert(1)</script>', 'level' => 1]]]);

		$this->assertStringNotContainsString('<script', $out['html']);
		$this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $out['html']);
		$this->assertStringContainsString('<h1', $out['html']);
	}//end testAScriptInAHeadingIsText()

	/**
	 * The footer is always there, last, with both compliance tokens.
	 *
	 * A marketer who typed the tokens away still sends them, and a block list
	 * without a footer gets one: that is what makes Blocks mode pass the
	 * compliance check by construction.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-saved-blocks-become-mail-safe-html-req-mbe-003
	 */
	public function testTheFooterAlwaysCarriesBothTokens(): void {
		$out = $this->render(
			[
				['id' => 'f', 'type' => 'footer', 'props' => ['text' => 'Sent by us']],
				['id' => 'h', 'type' => 'heading', 'props' => ['text' => 'Autumn news']],
			]
		);

		$this->assertStringContainsString('{{unsubscribe_link}}', $out['html']);
		$this->assertStringContainsString('{{physical_address}}', $out['html']);
		$this->assertGreaterThan(strpos($out['html'], 'Autumn news'), strpos($out['html'], 'Sent by us'));
		$this->assertStringContainsString('{{unsubscribe_link}}', $out['text']);

		$bare = $this->render([['id' => 'h', 'type' => 'heading', 'props' => ['text' => 'Only a heading']]]);
		$this->assertStringContainsString('{{unsubscribe_link}}', $bare['html']);
		$this->assertStringContainsString('{{physical_address}}', $bare['html']);
	}//end testTheFooterAlwaysCarriesBothTokens()

	/**
	 * Markdown keeps its allow-list and nothing else; links keep three schemes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-saved-blocks-become-mail-safe-html-req-mbe-003
	 */
	public function testMarkdownKeepsItsAllowList(): void {
		$out = $this->render(
			[
				[
					'id' => 't',
					'type' => 'text',
					'props' => ['markdown' => "Hello **bold** and *it*.\n\n- one\n- two\n\n[site](https://example.nl) [bad](javascript:alert(1)) [mail](mailto:a@b.nl) <b>raw</b>"],
				],
			]
		);

		$this->assertStringContainsString('<strong>bold</strong>', $out['html']);
		$this->assertStringContainsString('<em>it</em>', $out['html']);
		$this->assertStringContainsString('<li', $out['html']);
		$this->assertStringContainsString('href="https://example.nl"', $out['html']);
		$this->assertStringContainsString('href="mailto:a@b.nl"', $out['html']);
		$this->assertStringNotContainsString('javascript:', $out['html']);
		$this->assertStringNotContainsString('<b>', $out['html']);
		$this->assertStringContainsString('&lt;b&gt;raw&lt;/b&gt;', $out['html']);
		$this->assertStringContainsString('bold', $out['text']);
		$this->assertStringNotContainsString('**', $out['text']);
	}//end testMarkdownKeepsItsAllowList()

	/**
	 * An image needs an http(s) address; a button keeps its colour and link.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-saved-blocks-become-mail-safe-html-req-mbe-003
	 */
	public function testImagesAndButtonsKeepTheirRules(): void {
		$out = $this->render(
			[
				['id' => 'i1', 'type' => 'image', 'props' => ['src' => 'https://cdn.example.nl/a.png', 'alt' => 'A tree', 'href' => 'https://example.nl']],
				['id' => 'i2', 'type' => 'image', 'props' => ['src' => 'data:image/png;base64,AAAA', 'alt' => 'Hidden']],
				['id' => 'b1', 'type' => 'button', 'props' => ['label' => 'Read more', 'href' => 'https://www.example.nl']],
				['id' => 'b2', 'type' => 'button', 'props' => ['label' => 'Bad', 'href' => 'javascript:alert(1)', 'color' => 'red;x:y']],
				['id' => 'a', 'type' => 'articles', 'props' => []],
				['id' => 'd', 'type' => 'divider', 'props' => []],
				['id' => 's', 'type' => 'spacer', 'props' => ['height' => 24]],
			]
		);

		$this->assertStringContainsString('src="https://cdn.example.nl/a.png"', $out['html']);
		$this->assertStringContainsString('alt="A tree"', $out['html']);
		$this->assertStringNotContainsString('data:image', $out['html']);
		$this->assertStringContainsString('href="https://www.example.nl"', $out['html']);
		$this->assertStringContainsString('#123456', $out['html']);
		$this->assertStringNotContainsString('javascript:', $out['html']);
		$this->assertStringNotContainsString('red;x:y', $out['html']);
		$this->assertStringContainsString('{{articles}}', $out['html']);
		$this->assertStringContainsString('<hr', $out['html']);
		$this->assertStringContainsString('Read more: https://www.example.nl', $out['text']);
		$this->assertStringContainsString('width="600"', $out['html']);
	}//end testImagesAndButtonsKeepTheirRules()

	/**
	 * Unknown block types and malformed entries are dropped, not sent.
	 *
	 * @return void
	 */
	public function testUnknownBlocksAreDropped(): void {
		$out = $this->render([['type' => 'iframe', 'props' => ['src' => 'https://x']], 'junk', ['type' => 'heading', 'props' => 'x']]);

		$this->assertStringNotContainsString('iframe', $out['html']);
		$this->assertStringContainsString('{{unsubscribe_link}}', $out['html']);
	}//end testUnknownBlocksAreDropped()

	/**
	 * Block lists are normalised: known types, a footer last, ids kept.
	 *
	 * @return void
	 */
	public function testNormaliseKeepsKnownBlocksAndOneFooterLast(): void {
		$blocks = (new MailBlockRenderer())->normalise(
			blocks: [
				['id' => 'f', 'type' => 'footer', 'props' => ['text' => 'x']],
				['id' => 'h', 'type' => 'heading', 'props' => ['text' => 'y']],
				['id' => 'z', 'type' => 'iframe', 'props' => []],
			]
		);

		$this->assertSame(['heading', 'footer'], array_column($blocks, 'type'));
		$this->assertSame('h', $blocks[0]['id']);
	}//end testNormaliseKeepsKnownBlocksAndOneFooterLast()
}//end class
