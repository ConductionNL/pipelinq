<?php
/**
 * Pipelinq MailBlockRenderer.
 *
 * Turns the blocks of an email template into the HTML that is sent and its
 * plain-text twin (marketing-block-editor). One renderer, on the server, so
 * the preview and the send can never differ. The layout is a single 600 pixel
 * column of tables with inline styles, which mail clients render predictably.
 * Every text value is escaped; markdown is converted with a narrow allow-list;
 * links keep http, https and mailto; images keep http and https. The footer
 * always carries the unsubscribe link and the physical address, so a template
 * made from blocks passes the compliance check by construction.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Marketing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-saved-blocks-become-mail-safe-html-req-mbe-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

/**
 * Renders template blocks to mail-safe HTML and text.
 *
 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-saved-blocks-become-mail-safe-html-req-mbe-003
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) One method per block type keeps each rule readable.
 */
class MailBlockRenderer {

	/**
	 * The block types a template may hold.
	 */
	public const TYPES = ['heading', 'text', 'image', 'button', 'divider', 'spacer', 'articles', 'footer'];

	/**
	 * The unsubscribe token the compliance check asks for.
	 */
	public const UNSUBSCRIBE_TOKEN = '{{unsubscribe_link}}';

	/**
	 * The physical address token PhysicalAddressRenderer fills in.
	 */
	public const ADDRESS_TOKEN = '{{physical_address}}';

	/**
	 * The marker the picked articles replace at preview and send time.
	 */
	public const ARTICLES_TOKEN = '{{articles}}';

	/**
	 * The button colour when neither the block nor the options name one.
	 */
	private const DEFAULT_COLOR = '#00679e';

	/**
	 * The editor fields a template is stored with, for the keys the input carries.
	 *
	 * `editorMode` is `blocks` or `html` (anything unknown is `html`, as
	 * before blocks existed); `blocks` is a list. A key the input leaves out
	 * is left out here, so a patch keeps what is stored.
	 *
	 * @param array<string, mixed> $input The payload or patch.
	 *
	 * @return array<string, mixed> `editorMode` and/or `blocks`.
	 *
	 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-html-templates-keep-working-req-mbe-004
	 */
	public function storedFields(array $input): array {
		$fields = [];
		if (array_key_exists('editorMode', $input) === true) {
			$fields['editorMode'] = 'html';
			if ($input['editorMode'] === 'blocks') {
				$fields['editorMode'] = 'blocks';
			}
		}

		if (array_key_exists('blocks', $input) === true) {
			$fields['blocks'] = [];
			if (is_array($input['blocks']) === true) {
				$fields['blocks'] = array_values($input['blocks']);
			}
		}

		return $fields;
	}//end storedFields()

	/**
	 * Keep the known blocks, in order, with exactly one footer, last.
	 *
	 * @param array<int|string, mixed> $blocks The blocks as sent by the form.
	 *
	 * @return array<int, array{id: string, type: string, props: array<string, mixed>}> The blocks.
	 *
	 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-saved-blocks-become-mail-safe-html-req-mbe-003
	 */
	public function normalise(array $blocks): array {
		$kept   = [];
		$footer = null;
		foreach ($blocks as $index => $block) {
			if (is_array($block) === false) {
				continue;
			}

			$type = (string)($block['type'] ?? '');
			if (in_array($type, self::TYPES, true) === false) {
				continue;
			}

			$props = [];
			if (is_array($block['props'] ?? null) === true) {
				$props = $block['props'];
			}

			$entry = [
				'id'    => (string)($block['id'] ?? ($type.'-'.$index)),
				'type'  => $type,
				'props' => $props,
			];

			if ($type === 'footer') {
				$footer = ($footer ?? $entry);
				continue;
			}

			$kept[] = $entry;
		}//end foreach

		$kept[] = ($footer ?? ['id' => 'footer', 'type' => 'footer', 'props' => []]);

		return $kept;
	}//end normalise()

	/**
	 * Render blocks to the HTML that is sent and its plain-text twin.
	 *
	 * @param array<int|string, mixed> $blocks  The blocks.
	 * @param array<string, mixed>     $options `primaryColor`: the theming colour for buttons.
	 *
	 * @return array{html: string, text: string} The bodies.
	 *
	 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-saved-blocks-become-mail-safe-html-req-mbe-003
	 */
	public function render(array $blocks, array $options = []): array {
		$color = $this->color(value: (string)($options['primaryColor'] ?? ''), fallback: self::DEFAULT_COLOR);
		$rows  = [];
		$text  = [];

		foreach ($this->normalise(blocks: $blocks) as $block) {
			[$row, $plain] = $this->renderBlock(type: $block['type'], props: $block['props'], color: $color);
			if ($row === '') {
				continue;
			}

			$rows[] = '<tr><td style="padding:8px 24px;">'.$row.'</td></tr>';
			if ($plain !== '') {
				$text[] = $plain;
			}
		}

		$html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f4f4;">'
			.'<tr><td align="center" style="padding:16px 0;">'
			.'<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" '
			.'style="width:600px;max-width:100%;background:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.5;color:#222222;">'
			.implode('', $rows)
			.'</table></td></tr></table>';

		return ['html' => $html, 'text' => implode("\n\n", $text)."\n"];
	}//end render()

	/**
	 * Render one block.
	 *
	 * @param string               $type  The block type.
	 * @param array<string, mixed> $props The block's properties.
	 * @param string               $color The default button colour.
	 *
	 * @return array{0: string, 1: string} The HTML cell content and the text.
	 */
	private function renderBlock(string $type, array $props, string $color): array {
		return match ($type) {
			'heading'  => $this->heading(props: $props),
			'text'     => $this->text(props: $props),
			'image'    => $this->image(props: $props),
			'button'   => $this->button(props: $props, color: $color),
			'divider'  => ['<hr style="border:0;border-top:1px solid #dddddd;margin:8px 0;">', '----'],
			'spacer'   => $this->spacer(props: $props),
			'articles' => [self::ARTICLES_TOKEN, self::ARTICLES_TOKEN],
			'footer'   => $this->footer(props: $props),
			default    => ['', ''],
		};
	}//end renderBlock()

	/**
	 * A heading, level 1 or 2.
	 *
	 * @param array<string, mixed> $props The properties.
	 *
	 * @return array{0: string, 1: string} The HTML and the text.
	 */
	private function heading(array $props): array {
		$text = trim((string)($props['text'] ?? ''));
		if ($text === '') {
			return ['', ''];
		}

		$level = 2;
		if ((int)($props['level'] ?? 2) === 1) {
			$level = 1;
		}

		$size = '22px';
		if ($level === 1) {
			$size = '28px';
		}

		return [
			'<h'.$level.' style="margin:0;font-size:'.$size.';line-height:1.3;">'.$this->escape(value: $text).'</h'.$level.'>',
			$text,
		];
	}//end heading()

	/**
	 * A text block: markdown through the allow-list.
	 *
	 * @param array<string, mixed> $props The properties.
	 *
	 * @return array{0: string, 1: string} The HTML and the text.
	 */
	private function text(array $props): array {
		$markdown = trim(str_replace("\r\n", "\n", (string)($props['markdown'] ?? '')));
		if ($markdown === '') {
			return ['', ''];
		}

		$html  = [];
		$plain = [];
		foreach (preg_split('/\n{2,}/', $markdown) as $paragraph) {
			$lines = explode("\n", $paragraph);
			$list  = $this->listKind(lines: $lines);
			if ($list !== null) {
				$items = [];
				foreach ($lines as $line) {
					$item    = preg_replace('/^\s*(?:[-*]|\d+\.)\s+/', '', $line);
					$items[] = '<li style="margin:0 0 4px;">'.$this->inline(value: (string)$item).'</li>';
					$plain[] = '- '.$this->plainInline(value: (string)$item);
				}

				$html[] = '<'.$list.' style="margin:0 0 12px;padding-left:24px;">'.implode('', $items).'</'.$list.'>';
				continue;
			}

			$parts = array_map(fn (string $line): string => $this->inline(value: $line), $lines);
			$html[]  = '<p style="margin:0 0 12px;">'.implode('<br>', $parts).'</p>';
			$plain[] = implode("\n", array_map(fn (string $line): string => $this->plainInline(value: $line), $lines));
		}//end foreach

		return [implode('', $html), implode("\n\n", $plain)];
	}//end text()

	/**
	 * Whether every line of a paragraph is a list item, and which kind.
	 *
	 * @param array<int, string> $lines The lines.
	 *
	 * @return string|null `ul`, `ol`, or null for a paragraph.
	 */
	private function listKind(array $lines): ?string {
		$bullets  = 0;
		$numbered = 0;
		foreach ($lines as $line) {
			if (preg_match('/^\s*[-*]\s+/', $line) === 1) {
				$bullets++;
			} else if (preg_match('/^\s*\d+\.\s+/', $line) === 1) {
				$numbered++;
			}
		}

		if ($bullets === count($lines)) {
			return 'ul';
		}

		if ($numbered === count($lines)) {
			return 'ol';
		}

		return null;
	}//end listKind()

	/**
	 * Inline markdown on one line: escape first, then bold, italic and links.
	 *
	 * Escaping first means a tag typed into the text can only ever be text;
	 * the allow-list then adds back the few elements it names.
	 *
	 * @param string $value The line.
	 *
	 * @return string The HTML.
	 */
	private function inline(string $value): string {
		$html = $this->escape(value: $value);
		$html = (string)preg_replace_callback(
			'/\[([^\]]+)\]\(([^)\s]+)\)/',
			function (array $match): string {
				$href = html_entity_decode($match[2], ENT_QUOTES, 'UTF-8');
				if ($this->isLink(url: $href, schemes: ['https', 'http', 'mailto']) === false) {
					return $match[1];
				}

				return '<a href="'.$this->escape(value: $href).'" style="color:inherit;">'.$match[1].'</a>';
			},
			$html
		);
		$html = (string)preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);
		$html = (string)preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/', '<em>$1</em>', $html);

		return (string)preg_replace('/(?<!\w)_(?!\s)(.+?)(?<!\s)_(?!\w)/', '<em>$1</em>', $html);
	}//end inline()

	/**
	 * Inline markdown on one line, as plain text.
	 *
	 * @param string $value The line.
	 *
	 * @return string The text.
	 */
	private function plainInline(string $value): string {
		$text = (string)preg_replace('/\[([^\]]+)\]\(([^)\s]+)\)/', '$1 ($2)', $value);

		return str_replace(['**', '*'], '', $text);
	}//end plainInline()

	/**
	 * An image: an http(s) address and alt text, optionally a link.
	 *
	 * @param array<string, mixed> $props The properties.
	 *
	 * @return array{0: string, 1: string} The HTML and the text.
	 */
	private function image(array $props): array {
		$src = trim((string)($props['src'] ?? ''));
		if ($this->isLink(url: $src, schemes: ['https', 'http']) === false) {
			return ['', ''];
		}

		$alt = trim((string)($props['alt'] ?? ''));
		$img = '<img src="'.$this->escape(value: $src).'" alt="'.$this->escape(value: $alt).'" width="552" '
			.'style="display:block;width:100%;max-width:552px;height:auto;border:0;">';

		$href = trim((string)($props['href'] ?? ''));
		if ($this->isLink(url: $href, schemes: ['https', 'http']) === true) {
			$img = '<a href="'.$this->escape(value: $href).'">'.$img.'</a>';
		}

		return [$img, $alt];
	}//end image()

	/**
	 * A button: a label and an http(s) address, in a colour.
	 *
	 * @param array<string, mixed> $props The properties.
	 * @param string               $color The default colour.
	 *
	 * @return array{0: string, 1: string} The HTML and the text.
	 */
	private function button(array $props, string $color): array {
		$label = trim((string)($props['label'] ?? ''));
		$href  = trim((string)($props['href'] ?? ''));
		if ($label === '' || $this->isLink(url: $href, schemes: ['https', 'http']) === false) {
			return ['', ''];
		}

		$background = $this->color(value: (string)($props['color'] ?? ''), fallback: $color);

		return [
			'<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
			.'<td style="border-radius:4px;background:'.$background.';">'
			.'<a href="'.$this->escape(value: $href).'" style="display:inline-block;padding:12px 24px;color:#ffffff;text-decoration:none;font-weight:bold;">'
			.$this->escape(value: $label).'</a></td></tr></table>',
			$label.': '.$href,
		];
	}//end button()

	/**
	 * Empty space of a given height.
	 *
	 * @param array<string, mixed> $props The properties.
	 *
	 * @return array{0: string, 1: string} The HTML and the text.
	 */
	private function spacer(array $props): array {
		$height = max(8, min(96, (int)($props['height'] ?? 24)));

		return ['<div style="height:'.$height.'px;line-height:'.$height.'px;font-size:1px;">&nbsp;</div>', ''];
	}//end spacer()

	/**
	 * The footer: the marketer's text with both compliance tokens, always.
	 *
	 * @param array<string, mixed> $props The properties.
	 *
	 * @return array{0: string, 1: string} The HTML and the text.
	 */
	private function footer(array $props): array {
		$text = trim((string)($props['text'] ?? ''));
		foreach ([self::ADDRESS_TOKEN, self::UNSUBSCRIBE_TOKEN] as $token) {
			if (str_contains($text, $token) === false) {
				$text = trim($text."\n".$token);
			}
		}

		$html = implode('<br>', array_map(fn (string $line): string => $this->escape(value: $line), explode("\n", $text)));

		return ['<p style="margin:16px 0 0;font-size:12px;color:#666666;">'.$html.'</p>', $text];
	}//end footer()

	/**
	 * Whether a url uses one of the allowed schemes.
	 *
	 * @param string             $url     The url.
	 * @param array<int, string> $schemes The schemes, without the colon.
	 *
	 * @return bool True when allowed.
	 */
	private function isLink(string $url, array $schemes): bool {
		$scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
		if ($scheme === '' || in_array($scheme, $schemes, true) === false) {
			return false;
		}

		if ($scheme === 'mailto') {
			return strlen($url) > 7;
		}

		return parse_url($url, PHP_URL_HOST) !== null;
	}//end isLink()

	/**
	 * A hex colour, or the fallback.
	 *
	 * @param string $value    The colour asked for.
	 * @param string $fallback The colour to use otherwise.
	 *
	 * @return string The colour.
	 */
	private function color(string $value, string $fallback): string {
		if (preg_match('/^#[0-9a-fA-F]{6}$/', trim($value)) === 1) {
			return trim($value);
		}

		return $fallback;
	}//end color()

	/**
	 * HTML-escape a value for a mail body, the way ArticleService does.
	 *
	 * @param string $value The raw value.
	 *
	 * @return string The escaped value.
	 */
	private function escape(string $value): string {
		return htmlspecialchars($value, (ENT_QUOTES | ENT_SUBSTITUTE), 'UTF-8');
	}//end escape()
}//end class
