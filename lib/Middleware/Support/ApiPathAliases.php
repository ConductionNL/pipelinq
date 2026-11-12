<?php

/**
 * The versioned `/api/v1/` aliases of Pipelinq's API routes.
 *
 * @category  Middleware
 * @package   OCA\Pipelinq\Middleware\Support
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * @spec openspec/specs/api-contract/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Middleware\Support;

/**
 * Serves every API route under `/api/v1/` as well as at its unversioned path,
 * which keeps working and is deprecated.
 *
 * `/api/<path>` is also served as `/api/v1/<path>`, with the same controller,
 * requirements and defaults. Routes that already carry a version
 * (`/api/v1/...`, `/api/zgw/zaken/v1/...`) and standard protocol paths
 * ({@see StandardProtocolPaths}) get no alias and are not deprecated.
 *
 * @category Middleware
 * @package  OCA\Pipelinq\Middleware\Support
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec openspec/specs/api-contract/spec.md
 */
final class ApiPathAliases {

	/**
	 * The prefix of the versioned paths.
	 *
	 * @var string
	 */
	public const VERSION_PREFIX = '/api/v1';

	/**
	 * The date the unversioned paths were deprecated (2026-11-12), as an RFC 9745 `Deprecation` value.
	 *
	 * @var string
	 */
	public const DEPRECATED_SINCE = '@1794441600';

	/**
	 * The pattern of a path that already names a version.
	 *
	 * @var string
	 */
	private const VERSIONED = '#^/api/(?:[a-z0-9-]+/)*v[0-9]{1,3}(?:/|$)#i';

	/**
	 * Whether a path is an unversioned API path, deprecated in favour of its `/api/v1/` alias.
	 *
	 * @param string $path An app-relative path or a request path under the app root.
	 *
	 * @return bool True for a deprecated unversioned API path.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) StandardProtocolPaths is a pure lookup, shared with routes.php.
	 *
	 * @spec openspec/specs/api-contract/spec.md
	 */
	public static function isDeprecated(string $path): bool {
		$relative = StandardProtocolPaths::appRelative(path: $path);
		if (str_starts_with($relative, '/api/') === false || preg_match(self::VERSIONED, $relative) === 1) {
			return false;
		}

		return StandardProtocolPaths::matches(path: $relative) === false;
	}//end isDeprecated()

	/**
	 * The `/api/v1/` path that replaces a deprecated path, keeping whatever precedes the app root.
	 *
	 * @param string $path A request path, or an app-relative path.
	 *
	 * @return string|null The successor path, or null when the path is not deprecated.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) StandardProtocolPaths is a pure lookup, shared with routes.php.
	 *
	 * @spec openspec/specs/api-contract/spec.md
	 */
	public static function successorOf(string $path): ?string {
		if (self::isDeprecated(path: $path) === false) {
			return null;
		}

		$relative = StandardProtocolPaths::appRelative(path: $path);
		$prefix   = substr($path, 0, (strlen($path) - strlen($relative)));

		return $prefix.self::versioned(path: $relative);
	}//end successorOf()

	/**
	 * A routes.php declaration with an `/api/v1/` alias of every unversioned API route.
	 *
	 * The aliases come BEFORE the original routes. Routes match in declaration
	 * order, so an unversioned route with a placeholder as its first segment
	 * (`/api/{slug}/...`) can never answer a `/api/v1/` request meant for an alias.
	 * Among themselves the aliases keep the order of the routes they copy.
	 *
	 * @param array<string,array<array-key,mixed>> $routes The declaration.
	 *
	 * @return array<string,array<array-key,mixed>> The declaration with the aliases added to `routes`.
	 *
	 * @spec openspec/specs/api-contract/spec.md
	 */
	public static function withVersionedAliases(array $routes): array {
		$declared = [];
		foreach (($routes['routes'] ?? []) as $route) {
			$declared[self::key(route: $route)] = true;
		}

		$aliases = [];
		foreach (($routes['routes'] ?? []) as $route) {
			$url = '/'.ltrim((string) ($route['url'] ?? ''), '/');
			if (self::isDeprecated(path: $url) === false || self::isApiFallback(route: $route) === true) {
				continue;
			}

			$alias = array_merge(
				$route,
				[
					'url'     => self::versioned(path: $url),
					'postfix' => ($route['postfix'] ?? '').'v1',
				]
			);
			if (isset($declared[self::key(route: $alias)]) === false) {
				$aliases[] = $alias;
			}
		}

		$routes['routes'] = array_merge($aliases, ($routes['routes'] ?? []));

		return $routes;
	}//end withVersionedAliases()

	/**
	 * Whether a route is the API fallback, a single placeholder after `/api/` that spans segments.
	 *
	 * It answers every API path no other route does, versioned ones included,
	 * so it needs no alias, and an alias placed before the versioned routes
	 * would take their requests.
	 *
	 * @param array<array-key,mixed> $route The route.
	 *
	 * @return bool True for the API fallback.
	 *
	 * @spec openspec/specs/api-contract/spec.md
	 */
	public static function isApiFallback(array $route): bool {
		$url = '/'.ltrim((string) ($route['url'] ?? ''), '/');
		if (preg_match('#^/api/\{(\w+)\}$#', $url, $match) !== 1) {
			return false;
		}

		$requirement = (string) ($route['requirements'][$match[1]] ?? '[^/]+');

		return preg_match('#^(?:'.$requirement.')$#', 'a/b') === 1;
	}//end isApiFallback()

	/**
	 * The verb and path a route registers, which two routes cannot share.
	 *
	 * @param array<array-key,mixed> $route The route.
	 *
	 * @return string The verb and the path with one leading slash.
	 */
	private static function key(array $route): string {
		return strtoupper((string) ($route['verb'] ?? 'GET')).' /'.ltrim((string) ($route['url'] ?? ''), '/');
	}//end key()

	/**
	 * The `/api/v1/` form of an unversioned API path.
	 *
	 * @param string $path An app-relative path starting with `/api/`.
	 *
	 * @return string The versioned path.
	 */
	private static function versioned(string $path): string {
		return self::VERSION_PREFIX.substr($path, strlen('/api'));
	}//end versioned()
}//end class
