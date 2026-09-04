<?php

/**
 * Pipelinq CampaignLinkDecorator.
 *
 * Appends GA4 campaign parameters (`utm_source`, `utm_medium`,
 * `utm_campaign`, `utm_content`) to every link in a blast body that does
 * not already carry them, so the site visit a mail causes is attributed
 * to the same campaign Portaliq sees on the `email_open` and
 * `email_click` events phase 0 reports (fleet traffic contract, sections
 * 1 and 6). Parameters the author wrote are kept as written; the
 * unsubscribe merge tag, in-page anchors and `mailto:`/`tel:` links are
 * never touched.
 *
 * Runs on the template body BEFORE per-delivery rendering, which puts it
 * ahead of {@see TrackingLinkService::injectTracking()}: the signed click
 * redirect then wraps a URL that already carries the parameters, and the
 * redirect target Portaliq's collector parses is the decorated one.
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-blast-links-carry-campaign-parameters
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * CampaignLinkDecorator: campaign slug, UTM map and the anchor rewrite.
 *
 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-blast-links-carry-campaign-parameters
 */
class CampaignLinkDecorator {

	/**
	 * App-config key for the per-tenant switch. `true` (the default) adds
	 * the parameters; `false` leaves every link as the author wrote it.
	 *
	 * @var string
	 */
	public const AUTO_CONFIG_KEY = 'blast.utm_auto';

	/**
	 * URL schemes that are never decorated: they are not web pages.
	 *
	 * @var array<int, string>
	 */
	private const SKIPPED_SCHEMES = ['mailto:', 'tel:', 'sms:', 'javascript:', 'data:'];

	/**
	 * Longest slug the campaign value may be.
	 *
	 * @var int
	 */
	private const SLUG_MAX_LENGTH = 80;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Pipelinq app config.
	 *
	 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-blast-links-carry-campaign-parameters
	 */
	public function __construct(
		private IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Whether decoration is switched on for this tenant.
	 *
	 * @return bool True unless `blast.utm_auto` is `false`.
	 *
	 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-blast-links-carry-campaign-parameters
	 */
	public function isEnabled(): bool {
		$raw = strtolower(trim($this->appConfig->getValueString(Application::APP_ID, self::AUTO_CONFIG_KEY, 'true')));
		return in_array($raw, ['false', '0', 'no', 'off'], true) === false;
	}//end isEnabled()

	/**
	 * The campaign value for a blast: its name as a slug, falling back to
	 * the template name and then to the blast id.
	 *
	 * @param array<string, mixed> $blast The blast row.
	 * @param array<string, mixed> $template The campaign template row, may be empty.
	 *
	 * @return string The slug, empty only when every source is empty.
	 *
	 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-blast-links-carry-campaign-parameters
	 */
	public function campaignFor(array $blast, array $template = []): string {
		$candidates = [(string)($blast['name'] ?? ''), (string)($template['name'] ?? ''), $this->blastId(blast: $blast)];
		foreach ($candidates as $candidate) {
			$slug = self::slugify(value: $candidate);
			if ($slug !== '') {
				return $slug;
			}
		}

		return '';
	}//end campaignFor()

	/**
	 * The four parameters a blast link carries.
	 *
	 * @param array<string, mixed> $blast The blast row.
	 * @param array<string, mixed> $template The campaign template row, may be empty.
	 *
	 * @return array<string, string> `utm_source`, `utm_medium`, `utm_campaign`, `utm_content`.
	 *
	 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-blast-links-carry-campaign-parameters
	 */
	public function utmFor(array $blast, array $template = []): array {
		return [
			'utm_source' => 'email',
			'utm_medium' => 'email',
			'utm_campaign' => $this->campaignFor(blast: $blast, template: $template),
			'utm_content' => $this->blastId(blast: $blast),
		];
	}//end utmFor()

	/**
	 * Decorate every eligible `<a href>` in a blast body.
	 *
	 * @param string $html The template or rendered body.
	 * @param array<string, mixed> $blast The blast row.
	 * @param array<string, mixed> $template The campaign template row, may be empty.
	 *
	 * @return string The body, unchanged when the setting is off or nothing qualified.
	 *
	 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-blast-links-carry-campaign-parameters
	 */
	public function decorate(string $html, array $blast, array $template = []): string {
		if ($html === '' || $this->isEnabled() === false) {
			return $html;
		}

		$utm = array_filter($this->utmFor(blast: $blast, template: $template), static fn (string $value): bool => $value !== '');
		if ($utm === []) {
			return $html;
		}

		$rewritten = preg_replace_callback(
			'/<a\b([^>]*?)\shref=(["\'])(.*?)\2([^>]*)>/i',
			function (array $matches) use ($utm): string {
				return $this->rewriteAnchor(matches: $matches, utm: $utm);
			},
			$html
		);

		if (is_string($rewritten) === false) {
			return $html;
		}

		return $rewritten;
	}//end decorate()

	/**
	 * Decorate one URL, or answer null when it must be left alone.
	 *
	 * Null covers the unsubscribe merge tag (any `{{`), in-page anchors,
	 * the non-web schemes, and a URL that already carries every parameter.
	 *
	 * @param string $url The href as the author wrote it, entities decoded.
	 * @param array<string, string> $utm The parameters to add when absent.
	 *
	 * @return string|null The decorated URL, or null to keep the original.
	 *
	 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-blast-links-carry-campaign-parameters
	 */
	public function decorateUrl(string $url, array $utm): ?string {
		$trimmed = trim($url);
		if ($this->isDecoratable(url: $trimmed) === false) {
			return null;
		}

		[$base, $fragment] = $this->splitOnce(value: $trimmed, separator: '#');
		[$path, $query] = $this->splitOnce(value: $base, separator: '?');

		$additions = $this->missingParameters(query: $query, utm: $utm);
		if ($additions === []) {
			return null;
		}

		$parts = array_filter([$query, implode('&', $additions)], static fn (string $part): bool => $part !== '');
		$result = ($path . '?' . implode('&', $parts));
		if ($fragment !== '') {
			$result .= ('#' . $fragment);
		}

		return $result;
	}//end decorateUrl()

	/**
	 * Lower-case ASCII slug: letters, digits and single hyphens.
	 *
	 * @param string $value Any string.
	 *
	 * @return string The slug, empty when nothing survives.
	 *
	 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-blast-links-carry-campaign-parameters
	 */
	public static function slugify(string $value): string {
		$clean = $value;
		if (preg_match('//u', $clean) !== 1) {
			$clean = (string)preg_replace('/[\x80-\xFF]/', '', $clean);
		}

		$ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $clean);
		if ($ascii === false) {
			$ascii = $clean;
		}

		$slug = (string)preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii));
		$slug = trim($slug, '-');

		return substr($slug, 0, self::SLUG_MAX_LENGTH);
	}//end slugify()

	/**
	 * Rewrite one `<a href="...">` match from `decorate()`.
	 *
	 * @param array<int, string> $matches Regex groups: 0=full, 1=attrs before href, 2=quote, 3=href, 4=attrs after href.
	 * @param array<string, string> $utm The parameters to add when absent.
	 *
	 * @return string The rewritten (or unchanged) anchor tag.
	 */
	private function rewriteAnchor(array $matches, array $utm): string {
		$decorated = $this->decorateUrl(url: html_entity_decode($matches[3], (ENT_QUOTES | ENT_HTML5)), utm: $utm);
		if ($decorated === null) {
			return $matches[0];
		}

		$escaped = str_replace('&', '&amp;', $decorated);

		return ('<a' . $matches[1] . ' href=' . $matches[2] . $escaped . $matches[2] . $matches[4] . '>');
	}//end rewriteAnchor()

	/**
	 * Whether a URL is a web link this decorator may touch.
	 *
	 * @param string $url The trimmed href.
	 *
	 * @return bool False for empty, anchors, merge tags and non-web schemes.
	 */
	private function isDecoratable(string $url): bool {
		if ($url === '' || str_starts_with($url, '#') === true || str_contains($url, '{{') === true) {
			return false;
		}

		$lower = strtolower($url);
		foreach (self::SKIPPED_SCHEMES as $scheme) {
			if (str_starts_with($lower, $scheme) === true) {
				return false;
			}
		}

		$hasScheme = (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) === 1);

		return $hasScheme === false || preg_match('/^https?:/i', $url) === 1;
	}//end isDecoratable()

	/**
	 * The `key=value` pairs to append: every wanted parameter the query
	 * does not already carry, keys compared case-insensitively.
	 *
	 * @param string $query The existing query string, may be empty.
	 * @param array<string, string> $utm The wanted parameters.
	 *
	 * @return array<int, string> Encoded pairs in the order of `$utm`.
	 */
	private function missingParameters(string $query, array $utm): array {
		$present = [];
		foreach (explode('&', $query) as $pair) {
			if ($pair === '') {
				continue;
			}

			$present[strtolower(urldecode((string)strtok($pair, '=')))] = true;
		}

		$additions = [];
		foreach ($utm as $key => $value) {
			if (isset($present[$key]) === false) {
				$additions[] = ($key . '=' . rawurlencode($value));
			}
		}

		return $additions;
	}//end missingParameters()

	/**
	 * The blast's id from `uuid`, `id`, `slug` or the `@self` envelope.
	 *
	 * @param array<string, mixed> $blast The blast row.
	 *
	 * @return string The id or empty.
	 */
	private function blastId(array $blast): string {
		$sources = [$blast];
		if (is_array($blast['@self'] ?? null) === true) {
			$sources[] = $blast['@self'];
		}

		foreach ($sources as $source) {
			foreach (['uuid', 'id', 'slug'] as $key) {
				$value = ($source[$key] ?? null);
				if (is_scalar($value) === true && (string)$value !== '') {
					return (string)$value;
				}
			}
		}

		return '';
	}//end blastId()

	/**
	 * Split a string on the first occurrence of a separator.
	 *
	 * @param string $value The string.
	 * @param string $separator One character.
	 *
	 * @return array{0: string, 1: string} Head and tail (tail empty when absent).
	 */
	private function splitOnce(string $value, string $separator): array {
		$position = strpos($value, $separator);
		if ($position === false) {
			return [$value, ''];
		}

		return [substr($value, 0, $position), substr($value, ($position + 1))];
	}//end splitOnce()
}//end class
