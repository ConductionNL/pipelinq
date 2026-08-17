<?php

/**
 * Route-table contract tests for the /api/settings surface.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\AppInfo
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
 * @spec openspec/specs/admin-settings/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pipelinq declares its own route table — it does NOT adopt
 * `\OCA\OpenRegister\AppHost\Routes::standard()` — and it ships its own
 * `SettingsController`, so `AppHost\Bootstrap::aliasControllerUnlessLeafDefinesIt()`
 * never aliases OpenRegister's generic controller in. Both halves of the
 * `settings#` surface are therefore owed by this app and can drift apart in
 * two directions:
 *
 *   - a route with no method  -> ReflectionException, HTTP 500;
 *   - a method with no route  -> the verb is simply not matched. Measured on
 *     the dev instance 2026-08-08: `PUT /api/settings` answered **405 Method
 *     Not Allowed** because no PUT entry existed, while GET and POST answered
 *     200.
 *
 * These tests EVALUATE the array returned by `appinfo/routes.php` rather than
 * grepping its source, so a route that is commented out, mistyped as a string
 * or placed inside an unreachable branch cannot satisfy them.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
class SettingsRouteContractTest extends TestCase {
	/**
	 * Load and evaluate the shipped route table.
	 *
	 * @return array<int, array<string, mixed>> The declared route entries.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	private function routes(): array {
		$table = include __DIR__ . '/../../../appinfo/routes.php';

		$this->assertIsArray($table, 'appinfo/routes.php must return an array');
		$this->assertArrayHasKey('routes', $table, 'appinfo/routes.php must declare a "routes" key');
		$this->assertIsArray($table['routes'], 'The "routes" key must hold an array');

		return $table['routes'];
	}//end routes()

	/**
	 * Find the index of a route entry by name + url + verb.
	 *
	 * @param array<int, array<string, mixed>> $routes The evaluated route table.
	 * @param string $name The route name.
	 * @param string $url The route url.
	 * @param string $verb The HTTP verb.
	 *
	 * @return int|null The zero-based index, or null when absent.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	private function indexOfRoute(array $routes, string $name, string $url, string $verb): ?int {
		foreach ($routes as $index => $route) {
			if (is_array($route) === false) {
				continue;
			}

			if (($route['name'] ?? null) === $name
				&& ($route['url'] ?? null) === $url
				&& ($route['verb'] ?? null) === $verb
			) {
				return $index;
			}
		}

		return null;
	}//end indexOfRoute()

	/**
	 * The canonical write verb must be routed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function testCanonicalPutSettingsRouteIsDeclared(): void {
		$routes = $this->routes();

		$this->assertNotNull($this->indexOfRoute($routes, 'settings#update', '/api/settings', 'PUT'),
			'appinfo/routes.php does not declare the canonical ADR-066 write '
			. '["name" => "settings#update", "url" => "/api/settings", "verb" => "PUT"]. '
			. 'Without it PUT /api/settings answers 405 Method Not Allowed.'
		);

	}//end testCanonicalPutSettingsRouteIsDeclared()

	/**
	 * The legacy POST alias must survive the conversion.
	 *
	 * `src/store/modules/settings.js::saveSettings()` and
	 * `src/views/settings/ExportConfigurationSettings.vue::save()` both POST
	 * to this exact url, so removing the alias would break the live admin UI.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function testLegacyPostSettingsRouteIsPreserved(): void {
		$routes = $this->routes();

		$this->assertNotNull($this->indexOfRoute($routes, 'settings#create', '/api/settings', 'POST'),
			'The legacy POST /api/settings route was removed — the settings store '
			. 'and ExportConfigurationSettings.vue still call it.'
		);

		$this->assertNotNull($this->indexOfRoute($routes, 'settings#index', '/api/settings', 'GET'),
			'The GET /api/settings read route was removed.'
		);

	}//end testLegacyPostSettingsRouteIsPreserved()

	/**
	 * Settings routes must precede the SPA wildcard catch-all (ADR-016).
	 *
	 * `dashboard#page` matches `/{path}` with `path => .*`, so any route
	 * declared after it is unreachable for GET. Ordering is a property of the
	 * evaluated array, which is why this test reads indices and not source
	 * lines.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function testSettingsRoutesPrecedeTheSpaCatchAll(): void {
		$routes = $this->routes();

		$catchAll = $this->indexOfRoute($routes, 'dashboard#page', '/{path}', 'GET');
		$this->assertNotNull($catchAll, 'The SPA catch-all route is missing — this test cannot be read.');

		$update = $this->indexOfRoute($routes, 'settings#update', '/api/settings', 'PUT');
		$this->assertNotNull($update, 'settings#update PUT is not declared.');

		$this->assertLessThan($catchAll,
			$update,
			'settings#update must be declared BEFORE the SPA wildcard catch-all (ADR-016).'
		);

	}//end testSettingsRoutesPrecedeTheSpaCatchAll()

	/**
	 * Every declared `settings#` route must target a public method.
	 *
	 * A route whose target method does not exist is a ReflectionException at
	 * dispatch (HTTP 500), not a 404 — the router matched the url already.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function testEverySettingsRouteTargetsAPublicControllerMethod(): void {
		$reflection = new ReflectionClass(\OCA\Pipelinq\Controller\SettingsController::class);

		$inspected = 0;
		$missing = [];

		foreach ($this->routes() as $route) {
			if (is_array($route) === false || is_string($route['name'] ?? null) === false) {
				continue;
			}

			if (str_starts_with($route['name'], 'settings#') === false) {
				continue;
			}

			$method = substr($route['name'], strlen('settings#'));
			$inspected++;

			if ($reflection->hasMethod($method) === false) {
				$missing[] = sprintf('SettingsController::%s() [%s %s]', $method, $route['verb'] ?? '?', $route['url'] ?? '?');
				continue;
			}

			$this->assertTrue($reflection->getMethod($method)->isPublic(),
				sprintf('SettingsController::%s() must be public to be dispatchable', $method)
			);
		}//end foreach

		// Positive control. An empty $missing list only means something if the
		// loop actually looked at routes; a broken name filter would produce
		// the same "no findings" as a healthy route table.
		$this->assertGreaterThan(
			0,
			$inspected,
			'No settings# route was inspected — the route-name filter matched nothing, '
			. 'so the empty finding list below proves nothing.'
		);

		$this->assertSame(
			[],
			$missing,
			"These routes point at methods SettingsController does not define. Each is a 500, not a 404:\n  - "
			. implode("\n  - ", $missing)
		);

	}//end testEverySettingsRouteTargetsAPublicControllerMethod()
}//end class
