<?php

/**
 * Pipelinq HtmlTextExtractor.
 *
 * Two pure reads over an HTML document, shared by the content-gap crawler and
 * the competitor page watch:
 *
 *  - {@see headline()} returns the document's title and its `h1` to `h3`
 *    headings, which is what "does this page answer that query" is decided on.
 *    The body copy is deliberately not included: a term mentioned once in a
 *    footer is not a page about that term, and including the body would make
 *    almost nothing a gap.
 *  - {@see fragment()} returns the text of the element a small CSS selector
 *    picks out, which is what a page watch compares.
 *
 * Parsing uses `DOMDocument` with libxml's own error collection turned on and
 * restored afterwards, the way `LogiusConnector` and `XWikiService` already do
 * it here. Real-world HTML is malformed and libxml complains loudly about it;
 * a warning per unclosed tag in the Nextcloud log is not information.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Search
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-our-own-pages-are-crawled-through-the-egress-plane
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Search;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Throwable;

/**
 * Title, headings and CSS-selected fragments out of an HTML document.
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-our-own-pages-are-crawled-through-the-egress-plane
 */
class HtmlTextExtractor {

	/**
	 * The elements whose text is never part of a page's meaning.
	 *
	 * @var array<int, string>
	 */
	private const IGNORED = ['script', 'style', 'noscript', 'template'];

	/**
	 * The title and the `h1` to `h3` headings of a document, as one string.
	 *
	 * @param string $html The document.
	 *
	 * @return string The extracted text, empty when nothing parses.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-our-own-pages-are-crawled-through-the-egress-plane
	 */
	public function headline(string $html): string {
		$xpath = $this->xpath(html: $html);
		if ($xpath === null) {
			return '';
		}

		$parts = [];
		foreach (['//title', '//h1', '//h2', '//h3'] as $query) {
			$nodes = $xpath->query($query);
			if ($nodes === false) {
				continue;
			}

			foreach ($nodes as $node) {
				$text = $this->textOf(node: $node);
				if ($text !== '') {
					$parts[] = $text;
				}
			}
		}

		return implode(' ', $parts);
	}//end headline()

	/**
	 * The text of the first element matching a small CSS selector.
	 *
	 * The supported subset is `#id`, `.class`, `tag`, `tag#id`, `tag.class`
	 * and a descendant chain of those separated by spaces. That is what a
	 * page watch needs, and a full selector engine is a dependency this app
	 * would carry for one feature.
	 *
	 * @param string $html The document.
	 * @param string $selector The CSS selector.
	 *
	 * @return string|null The fragment text, or null when the selector matches nothing.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-page-watch-diffs-a-selected-fragment-and-stores-a-fingerprint
	 */
	public function fragment(string $html, string $selector): ?string {
		$xpath = $this->xpath(html: $html);
		$expression = $this->toXPath(selector: $selector);
		if ($xpath === null || $expression === null) {
			return null;
		}

		$nodes = $xpath->query($expression);
		if ($nodes === false || $nodes->length === 0) {
			return null;
		}

		return $this->textOf(node: $nodes->item(0));
	}//end fragment()

	/**
	 * Translate the supported CSS subset into an XPath expression.
	 *
	 * @param string $selector The CSS selector.
	 *
	 * @return string|null The expression, or null when the selector is not supported.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-page-watch-diffs-a-selected-fragment-and-stores-a-fingerprint
	 */
	public function toXPath(string $selector): ?string {
		$parts = preg_split('/\s+/', trim($selector));
		if (is_array($parts) === false || $parts === [] || $parts === ['']) {
			return null;
		}

		$expression = '';
		foreach ($parts as $part) {
			$step = $this->step(part: $part);
			if ($step === null) {
				return null;
			}

			$expression .= ('//' . $step);
		}

		return $expression;
	}//end toXPath()

	/**
	 * One selector step as an XPath node test with predicates.
	 *
	 * @param string $part The step, such as `div#main` or `.teaser`.
	 *
	 * @return string|null The node test, or null when unsupported.
	 */
	private function step(string $part): ?string {
		if (preg_match('/^([a-zA-Z][a-zA-Z0-9-]*)?(#[A-Za-z0-9_-]+)?((?:\.[A-Za-z0-9_-]+)*)$/', $part, $matches) !== 1) {
			return null;
		}

		// The pattern's three groups are all optional, so preg_match fills
		// every offset; phpstan knows it and rejects a `??` here.
		$tag = $matches[1];
		$id = $matches[2];
		$classes = $matches[3];
		if ($tag === '' && $id === '' && $classes === '') {
			return null;
		}

		$test = '*';
		if ($tag !== '') {
			$test = strtolower($tag);
		}

		if ($id !== '') {
			$test .= ('[@id=' . $this->literal(value: substr($id, 1)) . ']');
		}

		foreach (array_filter(explode('.', ltrim($classes, '.'))) as $class) {
			$test .= ('[contains(concat(" ", normalize-space(@class), " "), ' . $this->literal(value: (' ' . $class . ' ')) . ')]');
		}

		return $test;
	}//end step()

	/**
	 * An XPath string literal that survives a quote in the value.
	 *
	 * @param string $value The value.
	 *
	 * @return string The literal.
	 */
	private function literal(string $value): string {
		if (str_contains($value, '"') === false) {
			return ('"' . $value . '"');
		}

		return ("concat('" . str_replace('"', "', '\"', '", $value) . "')");
	}//end literal()

	/**
	 * The normalised text of a node, with script and style content removed.
	 *
	 * @param mixed $node The DOM node.
	 *
	 * @return string The text.
	 */
	private function textOf(mixed $node): string {
		if ($node instanceof DOMElement === false) {
			if (is_object($node) === true && property_exists($node, 'textContent') === true) {
				return $this->collapse(value: (string)$node->textContent);
			}

			return '';
		}

		$clone = $node->cloneNode(true);
		if ($clone instanceof DOMElement === true) {
			foreach (self::IGNORED as $tag) {
				$found = $clone->getElementsByTagName($tag);
				for ($index = ($found->length - 1); $index >= 0; $index--) {
					$unwanted = $found->item($index);
					if ($unwanted !== null && $unwanted->parentNode !== null) {
						$unwanted->parentNode->removeChild($unwanted);
					}
				}
			}

			return $this->collapse(value: (string)$clone->textContent);
		}

		return $this->collapse(value: (string)$node->textContent);
	}//end textOf()

	/**
	 * Collapse whitespace and trim.
	 *
	 * @param string $value The text.
	 *
	 * @return string
	 */
	private function collapse(string $value): string {
		$collapsed = preg_replace('/\s+/u', ' ', $value);
		if (is_string($collapsed) === false) {
			$collapsed = $value;
		}

		return trim($collapsed);
	}//end collapse()

	/**
	 * Parse the document and hand back an XPath cursor over it.
	 *
	 * @param string $html The document.
	 *
	 * @return DOMXPath|null The cursor, or null when nothing parses.
	 */
	private function xpath(string $html): ?DOMXPath {
		if (trim($html) === '') {
			return null;
		}

		$previous = libxml_use_internal_errors(true);
		try {
			$document = new DOMDocument();
			$loaded = $document->loadHTML(
				('<?xml encoding="UTF-8">' . $html),
				(LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)
			);
			if ($loaded === false) {
				return null;
			}

			return new DOMXPath($document);
		} catch (Throwable) {
			return null;
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}
	}//end xpath()
}//end class
