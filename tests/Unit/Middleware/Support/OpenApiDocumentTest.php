<?php

/**
 * Unit tests that keep openapi.json and appinfo/routes.php in step.
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

use OCA\Pipelinq\Middleware\Support\StandardProtocolPaths;
use PHPUnit\Framework\TestCase;

/**
 * The OpenAPI document describes the `/api/v1/` paths, and exactly the ones
 * the app routes: no documented path without a route, no versioned route
 * left out.
 *
 * @covers \OCA\Pipelinq\Middleware\Support\StandardProtocolPaths
 * @uses   \OCA\Pipelinq\Middleware\Support\ApiPathAliases
 */
class OpenApiDocumentTest extends TestCase {

	/**
	 * The document is OpenAPI 3.0 and its server is the versioned API root.
	 *
	 * @return void
	 */
	public function testTheServerIsVersioned(): void {
		$document = self::document();

		$this->assertStringStartsWith(prefix: '3.0.', string: $document['openapi']);
		$this->assertStringEndsWith(suffix: '/apps/pipelinq/api/v1', string: $document['servers'][0]['url']);
	}//end testTheServerIsVersioned()

	/**
	 * Every documented operation has a versioned route, and every versioned route is documented.
	 *
	 * @return void
	 */
	public function testTheDocumentMatchesTheRoutes(): void {
		// Operations OpenRegister's AppHost routes for the app exist only when OpenRegister is installed.
		$hosted     = class_exists('OCA\OpenRegister\AppHost\Routes');
		$documented = [];
		foreach (self::document()['paths'] as $path => $item) {
			foreach ($item as $verb => $operation) {
				if (in_array($verb, ['get', 'put', 'post', 'delete', 'patch', 'options', 'head'], true) === true
					&& ($hosted === true || ($operation['x-provided-by'] ?? null) !== 'openregister')
				) {
					$documented[] = strtoupper($verb).' '.self::shape(path: '/api/v1'.$path);
				}
			}
		}

		$routed = [];
		$declared = require dirname(__DIR__, 4).'/appinfo/routes.php';
		foreach ($declared['routes'] as $route) {
			$url = '/'.ltrim((string) $route['url'], '/');
			if (str_starts_with($url, '/api/v1/') === true && StandardProtocolPaths::matches(path: $url) === false) {
				$routed[] = strtoupper((string) ($route['verb'] ?? 'GET')).' '.self::shape(path: $url);
			}
		}

		$routed = array_values(array_unique($routed));
		sort($documented);
		sort($routed);
		$this->assertSame(expected: [], actual: array_values(array_diff($documented, $routed)), message: 'documented but not routed');
		$this->assertSame(expected: [], actual: array_values(array_diff($routed, $documented)), message: 'routed but not documented');
	}//end testTheDocumentMatchesTheRoutes()

	/**
	 * The decoded openapi.json.
	 *
	 * @return array<string,mixed>
	 */
	private static function document(): array {
		$document = json_decode((string) file_get_contents(dirname(__DIR__, 4).'/openapi.json'), true);
		self::assertIsArray(actual: $document);

		return $document;
	}//end document()

	/**
	 * A path with its placeholder names left out, so `/a/{id}` and `/a/{uuid}` compare equal.
	 *
	 * @param string $path The path.
	 *
	 * @return string The shape of the path.
	 */
	private static function shape(string $path): string {
		return (string) preg_replace('#\{[^}]+\}#', '{}', $path);
	}//end shape()
}//end class
