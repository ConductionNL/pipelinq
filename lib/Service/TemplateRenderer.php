<?php

/**
 * Pipelinq TemplateRenderer.
 *
 * Lightweight Mustache-style template renderer for the Berichtenbox
 * bridge. Renders subject + body from a BerichtenboxTemplate array with
 * the variables zaakId, status, gemeente, deepLink, deadline, messageId.
 * The renderer is deliberately tiny — no nested sections, no partials —
 * so we don't drag in a third-party Mustache lib for what is six simple
 * placeholder substitutions. The implementation HTML-escapes
 * substitution values by default (using `{{var}}`), with the `{{{var}}}`
 * triple-brace form preserving raw markup (Mustache convention) — used
 * by deepLink anchor rendering.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
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
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-template-013
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-deeplink-014
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DOMDocument;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Render a BerichtenboxTemplate into a rendered subject + body.
 */
class TemplateRenderer {
	/**
	 * Maximum subject length per BBK 1.7.
	 *
	 * @var int
	 */
	public const MAX_SUBJECT_CHARS = 200;

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Render a template.
	 *
	 * @param array $template The OR-array form of the BerichtenboxTemplate
	 *                        (must contain subject + body).
	 * @param array $variables Substitution variables.
	 *
	 * @return array{subject: string, body: string, deepLink: ?string}
	 *
	 * @throws RuntimeException If the body fails XHTML strict validation.
	 */
	public function render(array $template, array $variables): array {
		$deepLink = null;
		$requires = (bool)($template['requiresDeepLink'] ?? false);
		if ($requires === true) {
			$deepLink = $this->buildDeepLink(template: $template, variables: $variables);
			if ($deepLink !== null) {
				$variables['deepLink'] = $deepLink;
			}
		}

		$subject = $this->renderString(source: (string)($template['subject'] ?? ''), variables: $variables);
		if (mb_strlen($subject) > self::MAX_SUBJECT_CHARS) {
			$subject = mb_substr($subject, 0, self::MAX_SUBJECT_CHARS);
		}

		$body = $this->renderString(source: (string)($template['body'] ?? ''), variables: $variables);

		$this->assertValidXhtml(body: $body);

		return [
			'subject' => $subject,
			'body' => $body,
			'deepLink' => $deepLink,
		];
	}//end render()

	/**
	 * Build a deep-link URL from the template's deepLinkBase + zaakId
	 * + messageId per REQ-DEEPLINK-014.
	 *
	 * @param array $template Template payload.
	 * @param array $variables Substitution vars (uses zaakId, messageId, status).
	 *
	 * @return string|null
	 *
	 * @spec exclude phpmd mechanical refactor
	 */
	public function buildDeepLink(array $template, array $variables): ?string {
		$base = (string)($template['deepLinkBase'] ?? '');
		if ($base === '') {
			$this->logger->warning(
				'BerichtenboxTemplate requiresDeepLink=true but deepLinkBase is empty.'
			);
			return null;
		}

		$query = http_build_query(
			[
				'zaakId' => (string)($variables['zaakId'] ?? ''),
				'status' => (string)($variables['status'] ?? ''),
				'ref' => (string)($variables['messageId'] ?? ''),
			]
		);
		$sep = '?';
		if (str_contains($base, '?') === true) {
			$sep = '&';
		}

		return $base . $sep . $query;
	}//end buildDeepLink()

	/**
	 * Render a template string with {{var}} and {{{var}}} substitution.
	 *
	 * @param string $source Template source.
	 * @param array $variables Vars.
	 *
	 * @return string
	 */
	private function renderString(string $source, array $variables): string {
		// Triple-brace (raw) first so the double-brace pass doesn't see it.
		$result = preg_replace_callback(
			'/\{\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}\}/',
			static function (array $matches) use ($variables): string {
				return (string)($variables[$matches[1]] ?? '');
			},
			$source
		);
		if ($result === null) {
			$result = $source;
		}

		$result = preg_replace_callback(
			'/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/',
			static function (array $matches) use ($variables): string {
				$value = (string)($variables[$matches[1]] ?? '');
				return htmlspecialchars($value, ENT_XHTML | ENT_QUOTES, 'UTF-8');
			},
			$result
		);

		return ($result ?? $source);
	}//end renderString()

	/**
	 * Assert that a rendered body parses as XHTML-strict.
	 *
	 * @param string $body Body content.
	 *
	 * @return void
	 *
	 * @throws RuntimeException If parsing fails.
	 */
	private function assertValidXhtml(string $body): void {
		$previous = libxml_use_internal_errors(true);
		try {
			$doc = new DOMDocument();
			$wrapped = '<?xml version="1.0" encoding="UTF-8"?><root>' . $body . '</root>';
			if ($doc->loadXML($wrapped) === false) {
				throw new RuntimeException('Rendered body is not valid XHTML strict.');
			}
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}
	}//end assertValidXhtml()
}//end class
