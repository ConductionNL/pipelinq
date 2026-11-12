<?php

/**
 * Unit tests for the `/api/v1/` aliases of the API routes.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Middleware\Support
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Middleware\Support;

use OCA\Pipelinq\Middleware\Support\ApiPathAliases;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every unversioned API route is also served under `/api/v1/`, the old paths
 * keep working, and no alias takes a request meant for another route.
 *
 * @covers \OCA\Pipelinq\Middleware\Support\ApiPathAliases
 * @uses   \OCA\Pipelinq\Middleware\Support\StandardProtocolPaths
 */
class ApiPathAliasesTest extends TestCase {

	/**
	 * Paths and their successor, null when the path is not deprecated.
	 *
	 * @return array<string,array{0:string,1:string|null}>
	 */
	public static function successors(): array {
		return [
			'api path'         => ['/api/things/7', '/api/v1/things/7'],
			'request path'     => ['/index.php/apps/pipelinq/api/things', '/index.php/apps/pipelinq/api/v1/things'],
			'already v1'       => ['/api/v1/things', null],
			'versioned family' => ['/api/zgw/zaken/v1/zaken', null],
			'protocol'         => ['/api/zgw/notificaties/inbox', null],
			'page'             => ['/apps/pipelinq/things', null],
		];
	}//end successors()

	/**
	 * Each path has the expected successor.
	 *
	 * @param string      $path      The path.
	 * @param string|null $successor The expected successor.
	 *
	 * @return void
	 */
	#[DataProvider('successors')]
	public function testTheSuccessorOfAPath(string $path, ?string $successor): void {
		$this->assertSame(expected: $successor, actual: ApiPathAliases::successorOf(path: $path));
		$this->assertSame(expected: $successor !== null, actual: ApiPathAliases::isDeprecated(path: $path));
	}//end testTheSuccessorOfAPath()

	/**
	 * Aliases come before the originals, which stay exactly as they were.
	 *
	 * @return void
	 */
	public function testAliasesComeBeforeTheOriginals(): void {
		$original = [
			'routes' => [
				['name' => 'things#index', 'url' => '/api/things/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
				['name' => 'things#update', 'url' => 'api/things', 'verb' => 'PUT', 'postfix' => 'put'],
				['name' => 'things#versioned', 'url' => '/api/v1/other', 'verb' => 'GET'],
				['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],
			],
		];

		$declared = ApiPathAliases::withVersionedAliases(routes: $original);

		$this->assertSame(
			expected: [
				['name' => 'things#index', 'url' => '/api/v1/things/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+'], 'postfix' => 'v1'],
				['name' => 'things#update', 'url' => '/api/v1/things', 'verb' => 'PUT', 'postfix' => 'putv1'],
			],
			actual: array_slice($declared['routes'], 0, 2)
		);
		$this->assertSame(expected: $original['routes'], actual: array_slice($declared['routes'], 2));
	}//end testAliasesComeBeforeTheOriginals()

	/**
	 * The shipped routes.php serves every unversioned API route under `/api/v1/`
	 * too, with the same controller, requirements and defaults, under a new name.
	 *
	 * @return void
	 */
	public function testEveryShippedApiRouteHasAVersionedTwin(): void {
		$routes = self::shippedRoutes();
		$byKey  = [];
		$names  = [];
		foreach ($routes as $route) {
			$byKey[$route['verb'].' '.self::url(route: $route)] = $route;
			$names[] = strtolower($route['name'].($route['postfix'] ?? ''));
		}

		$twins = 0;
		foreach ($routes as $route) {
			$url = self::url(route: $route);
			if (ApiPathAliases::isDeprecated(path: $url) === false) {
				continue;
			}

			$twin = ($byKey[$route['verb'].' /api/v1'.substr($url, 4)] ?? null);
			$this->assertNotNull(actual: $twin, message: $route['verb'].' '.$url.' has no /api/v1/ twin');
			$this->assertSame(expected: $route['name'], actual: $twin['name']);
			$this->assertSame(expected: ($route['requirements'] ?? null), actual: ($twin['requirements'] ?? null));
			$this->assertSame(expected: ($route['defaults'] ?? null), actual: ($twin['defaults'] ?? null));
			$twins++;
		}

		$this->assertGreaterThan(expected: 0, actual: $twins);
		$this->assertSame(expected: count($names), actual: count(array_unique($names)), message: 'a route name is registered twice');
	}//end testEveryShippedApiRouteHasAVersionedTwin()

	/**
	 * No alias answers a request meant for a route that was already versioned.
	 *
	 * Routes match in declaration order, so for each versioned route the first
	 * route that matches a sample of its path must be that route, or an alias
	 * standing in for an earlier unversioned route exactly as before.
	 *
	 * @return void
	 */
	public function testNoAliasTakesARequestMeantForAnotherRoute(): void {
		$routes  = self::shippedRoutes();
		$checked = 0;
		foreach ($routes as $route) {
			$url    = self::url(route: $route);
			$sample = self::sample(route: $route);
			if (str_starts_with($url, '/api/v1/') === false || $sample === null) {
				continue;
			}

			$first = $route;
			foreach ($routes as $candidate) {
				if ($candidate['verb'] === $route['verb'] && preg_match(self::pattern(route: $candidate), $sample) === 1) {
					$first = $candidate;
					break;
				}
			}

			$checked++;
			if ($first === $route || self::isAlias(route: $route) === true) {
				continue;
			}

			$this->assertFalse(
				condition: self::isAlias(route: $first),
				message: $route['verb'].' '.$sample.' is meant for '.$route['name'].' but the alias of '.$first['name'].' answers it'
			);
		}

		$this->assertGreaterThan(expected: 0, actual: $checked);
	}//end testNoAliasTakesARequestMeantForAnotherRoute()

	/**
	 * The routes the app declares, with the aliases.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function shippedRoutes(): array {
		$declared = require dirname(__DIR__, 4).'/appinfo/routes.php';

		return $declared['routes'];
	}//end shippedRoutes()

	/**
	 * The url of a route with one leading slash.
	 *
	 * @param array<string,mixed> $route The route.
	 *
	 * @return string The url.
	 */
	private static function url(array $route): string {
		return '/'.ltrim((string) $route['url'], '/');
	}//end url()

	/**
	 * Whether a route is an alias added by ApiPathAliases.
	 *
	 * @param array<string,mixed> $route The route.
	 *
	 * @return bool True for an alias.
	 */
	private static function isAlias(array $route): bool {
		return str_ends_with((string) ($route['postfix'] ?? ''), 'v1') === true
			&& str_starts_with(self::url(route: $route), '/api/v1/') === true;
	}//end isAlias()

	/**
	 * The regular expression a route's url compiles to, as Symfony routing matches it.
	 *
	 * @param array<string,mixed> $route The route.
	 *
	 * @return string The pattern.
	 */
	private static function pattern(array $route): string {
		$requirements = ($route['requirements'] ?? []);
		$defaults     = ($route['defaults'] ?? []);
		$pattern      = '';
		foreach ((array) preg_split('#(/?\{\w+\})#', self::url(route: $route), -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
			if (preg_match('#^(/?)\{(\w+)\}$#', (string) $part, $match) !== 1) {
				$pattern .= preg_quote((string) $part, '#');
				continue;
			}

			$pattern .= '(?:'.preg_quote($match[1], '#').'(?:'.($requirements[$match[2]] ?? '[^/]+').'))';
			if (array_key_exists($match[2], $defaults) === true) {
				$pattern .= '?';
			}
		}

		return '#^'.$pattern.'$#';
	}//end pattern()

	/**
	 * A request path that the route matches, or null when no sample value fits its requirements.
	 *
	 * @param array<string,mixed> $route The route.
	 *
	 * @return string|null The path.
	 */
	private static function sample(array $route): ?string {
		$path = self::url(route: $route);
		foreach (($route['requirements'] ?? []) as $name => $requirement) {
			foreach (['sample', '1', 'a-b', 'a.b', 'a/b'] as $value) {
				if (preg_match('#^(?:'.$requirement.')$#', $value) === 1) {
					$path = str_replace('{'.$name.'}', $value, $path);
					break;
				}
			}
		}

		$path = (string) preg_replace('#\{\w+\}#', 'sample', $path);

		if (preg_match(self::pattern(route: $route), $path) !== 1) {
			return null;
		}

		return $path;
	}//end sample()
}//end class
