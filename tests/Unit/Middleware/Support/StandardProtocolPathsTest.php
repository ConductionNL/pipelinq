<?php

/**
 * Unit tests for the recognition of standard protocol paths.
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Paths that speak a protocol defined elsewhere are recognised, as app-relative
 * paths and as full request paths, and near misses are not.
 *
 * @covers \OCA\Pipelinq\Middleware\Support\StandardProtocolPaths
 */
class StandardProtocolPathsTest extends TestCase {

	/**
	 * Paths and their app-relative form.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function relativePaths(): array {
		return [
			'app-relative'          => ['/api/health', '/api/health'],
			'without leading slash' => ['api/health', '/api/health'],
			'extra leading slashes' => ['//api/health', '/api/health'],
			'index.php request'     => ['/index.php/apps/pipelinq/api/zgw/inbox', '/api/zgw/inbox'],
			'pretty url request'    => ['/apps/pipelinq/api/metrics', '/api/metrics'],
			'subdirectory install'  => ['/cloud/index.php/apps/pipelinq/api/things/1', '/api/things/1'],
			'app root alone'        => ['/apps/pipelinq', '/apps/pipelinq'],
			'other app'             => ['/index.php/apps/pipelinqx/api/health', '/index.php/apps/pipelinqx/api/health'],
		];
	}//end relativePaths()

	/**
	 * Each path has the expected app-relative form.
	 *
	 * @param string $path     The path.
	 * @param string $relative The expected app-relative path.
	 *
	 * @return void
	 */
	#[DataProvider('relativePaths')]
	public function testTheAppRelativeFormOfAPath(string $path, string $relative): void {
		$this->assertSame(expected: $relative, actual: StandardProtocolPaths::appRelative(path: $path));
	}//end testTheAppRelativeFormOfAPath()

	/**
	 * Paths and whether they speak a standard protocol.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public static function protocolPaths(): array {
		return [
			'zgw notification'          => ['/api/zgw/notificaties/inbox', true],
			'zgw request path'          => ['/index.php/apps/pipelinq/api/zgw/zaken/v1/zaken', true],
			'zgw without trailing path' => ['/api/zgw', false],
			'blast webhook'             => ['/api/blast-webhooks/mail', true],
			'messaging webhook'         => ['/api/messaging-webhooks/sms', true],
			'generic webhook'           => ['/api/webhook/berichtenbox', true],
			'webhook without provider'  => ['/api/webhook', false],
			'pos payment webhook'       => ['/api/pos-payment-webhook', true],
			'appointment payment'       => ['/api/appointment-payment-webhook/7', true],
			'payment webhook suffix'    => ['/api/pos-payment-webhooks', false],
			'cti webhook'               => ['/api/cti/webhook/call', true],
			'cti without webhook'       => ['/api/cti/calls', false],
			'health'                    => ['/apps/pipelinq/api/health', true],
			'health sub path'           => ['/api/health/details', false],
			'healthz'                   => ['/api/healthz', false],
			'metrics'                   => ['/api/metrics', true],
			'metrics sub path'          => ['/api/metrics/today', false],
			'versioned health'          => ['/api/v1/health', false],
			'own api'                   => ['/api/leads', false],
			'page'                      => ['/apps/pipelinq/leads', false],
		];
	}//end protocolPaths()

	/**
	 * Each path is recognised as a standard protocol path or not.
	 *
	 * @param string $path     The path.
	 * @param bool   $standard Whether it speaks a standard protocol.
	 *
	 * @return void
	 */
	#[DataProvider('protocolPaths')]
	public function testWhetherAPathSpeaksAStandardProtocol(string $path, bool $standard): void {
		$this->assertSame(expected: $standard, actual: StandardProtocolPaths::matches(path: $path));
	}//end testWhetherAPathSpeaksAStandardProtocol()

	/**
	 * Every listed protocol is named and its pattern is a valid expression anchored at the API root.
	 *
	 * @return void
	 */
	public function testEveryPatternIsAnchoredAtTheApiRoot(): void {
		$this->assertNotSame(expected: [], actual: StandardProtocolPaths::PATTERNS);
		foreach (StandardProtocolPaths::PATTERNS as $name => $pattern) {
			$this->assertNotSame(expected: '', actual: trim((string) $name));
			$this->assertNotFalse(condition: @preg_match($pattern, ''), message: $name.' has an invalid pattern');
			$this->assertStringStartsWith(prefix: '#^/api/', string: $pattern, message: $name.' is not anchored at /api/');
		}
	}//end testEveryPatternIsAnchoredAtTheApiRoot()
}//end class
