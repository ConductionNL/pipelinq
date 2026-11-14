<?php

/**
 * Unit tests for the edge cases of the `/api/v1/` route aliases.
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
 * Declarations without routes, twins the app already declares, protocol and
 * page routes, and API fallbacks whose requirement does not span segments.
 *
 * @covers \OCA\Pipelinq\Middleware\Support\ApiPathAliases
 * @uses   \OCA\Pipelinq\Middleware\Support\StandardProtocolPaths
 */
class ApiPathAliasesEdgeCasesTest extends TestCase {

	/**
	 * The deprecation date is midnight UTC on 2026-11-12, in the RFC 9745 `@<seconds>` form.
	 *
	 * @return void
	 */
	public function testTheDeprecationDate(): void {
		$this->assertSame(expected: '/api/v1', actual: ApiPathAliases::VERSION_PREFIX);
		$this->assertStringStartsWith(prefix: '@', string: ApiPathAliases::DEPRECATED_SINCE);
		$this->assertSame(expected: '2026-11-12T00:00:00+00:00', actual: gmdate('c', (int) substr(ApiPathAliases::DEPRECATED_SINCE, 1)));
	}//end testTheDeprecationDate()

	/**
	 * A declaration without routes gets an empty route list and keeps its other keys.
	 *
	 * @return void
	 */
	public function testADeclarationWithoutRoutes(): void {
		$declared = ApiPathAliases::withVersionedAliases(routes: ['ocs' => [['name' => 'x#y', 'url' => '/x', 'verb' => 'GET']]]);

		$this->assertSame(
			expected: ['ocs' => [['name' => 'x#y', 'url' => '/x', 'verb' => 'GET']], 'routes' => []],
			actual: $declared
		);
	}//end testADeclarationWithoutRoutes()

	/**
	 * A twin the app already declares under `/api/v1/` is not added a second
	 * time, also when the verb is left to its GET default, and the other
	 * aliases go right after it.
	 *
	 * @return void
	 */
	public function testADeclaredTwinIsNotAddedAgain(): void {
		$routes = [
			['name' => 'leads#index', 'url' => '/api/leads', 'verb' => 'GET'],
			['name' => 'leads#create', 'url' => '/api/leads', 'verb' => 'POST'],
			['name' => 'leads#indexV1', 'url' => 'api/v1/leads'],
		];

		$declared = ApiPathAliases::withVersionedAliases(routes: ['routes' => $routes]);

		$this->assertSame(
			expected: [
				$routes[0],
				$routes[1],
				$routes[2],
				['name' => 'leads#create', 'url' => '/api/v1/leads', 'verb' => 'POST', 'postfix' => 'v1'],
			],
			actual: $declared['routes']
		);
	}//end testADeclaredTwinIsNotAddedAgain()

	/**
	 * Without a declared `/api/v1/` route the aliases come first, and protocol,
	 * page and versioned-family routes get none.
	 *
	 * @return void
	 */
	public function testOnlyOwnUnversionedApiRoutesGetAnAlias(): void {
		$routes = [
			['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
			['name' => 'health#check', 'url' => '/api/health', 'verb' => 'GET'],
			['name' => 'zgw#zaken', 'url' => '/api/zgw/zaken/v1/zaken', 'verb' => 'GET'],
			['name' => 'cti#webhook', 'url' => '/api/cti/webhook/{provider}', 'verb' => 'POST'],
			['name' => 'leads#show', 'url' => '/api/leads/{id}', 'verb' => 'GET', 'defaults' => ['id' => 0]],
		];

		$declared = ApiPathAliases::withVersionedAliases(routes: ['routes' => $routes]);

		$this->assertSame(
			expected: array_merge(
				[['name' => 'leads#show', 'url' => '/api/v1/leads/{id}', 'verb' => 'GET', 'defaults' => ['id' => 0], 'postfix' => 'v1']],
				$routes
			),
			actual: $declared['routes']
		);
	}//end testOnlyOwnUnversionedApiRoutesGetAnAlias()

	/**
	 * Routes and whether they are the API fallback.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:bool}>
	 */
	public static function fallbacks(): array {
		return [
			'spanning requirement'    => [['url' => '/api/{path}', 'requirements' => ['path' => '.+']], true],
			'without leading slash'   => [['url' => 'api/{path}', 'requirements' => ['path' => '.*']], true],
			'no requirement'          => [['url' => '/api/{path}'], false],
			'segment requirement'     => [['url' => '/api/{path}', 'requirements' => ['path' => '[a-z]+']], false],
			'requirement on other'    => [['url' => '/api/{path}', 'requirements' => ['id' => '.+']], false],
			'deeper placeholder'      => [['url' => '/api/leads/{path}', 'requirements' => ['path' => '.+']], false],
			'not under api'           => [['url' => '/{path}', 'requirements' => ['path' => '.+']], false],
			'without url'             => [[], false],
		];
	}//end fallbacks()

	/**
	 * Each route is the API fallback or not.
	 *
	 * @param array<string,mixed> $route    The route.
	 * @param bool                $fallback Whether it is the API fallback.
	 *
	 * @return void
	 */
	#[DataProvider('fallbacks')]
	public function testWhetherARouteIsTheApiFallback(array $route, bool $fallback): void {
		$this->assertSame(expected: $fallback, actual: ApiPathAliases::isApiFallback(route: $route));
	}//end testWhetherARouteIsTheApiFallback()

	/**
	 * Paths and their successor, null when the path is not deprecated.
	 *
	 * @return array<string,array{0:string,1:string|null}>
	 */
	public static function successors(): array {
		return [
			'subdirectory install' => ['/cloud/index.php/apps/pipelinq/api/leads', '/cloud/index.php/apps/pipelinq/api/v1/leads'],
			'api root alone'       => ['/api/', '/api/v1/'],
			'upper case version'   => ['/api/V2/leads', null],
			'versioned family'     => ['/api/zgw/zaken/v1', null],
			'version-like segment' => ['/api/v1leads', '/api/v1/v1leads'],
			'webhook'              => ['/api/messaging-webhooks/sms', null],
			'api without slash'    => ['/api', null],
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
}//end class
