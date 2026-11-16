<?php

/**
 * The API paths of Pipelinq that speak a protocol defined elsewhere.
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
 * Recognises the API paths whose request, response and error formats a
 * standard or a peer instance defines, so the app's own API conventions
 * (versioned paths, problem details, the `API-Version` header) leave them alone.
 *
 * Paths are relative to the app root (`/api/...`); a full request path such as
 * `/index.php/apps/pipelinq/api/health` is accepted too.
 *
 * @category Middleware
 * @package  OCA\Pipelinq\Middleware\Support
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec openspec/specs/api-contract/spec.md
 */
final class StandardProtocolPaths {

	/**
	 * The app root that precedes every app-relative path in a request path.
	 *
	 * @var string
	 */
	private const APP_ROOT = '/apps/pipelinq';

	/**
	 * One pattern per protocol, matched against the app-relative path.
	 *
	 * @var array<string,non-empty-string>
	 */
	public const PATTERNS = [
		'ZGW Notificaties'                                      => '#^/api/zgw/#',
		'Provider webhooks (mail, SMS, WhatsApp, Berichtenbox)' => '#^/api/(?:blast-webhooks|messaging-webhooks|webhook)/#',
		'Payment provider webhooks'                             => '#^/api/(?:pos-payment-webhook|appointment-payment-webhook)(?:/|$)#',
		'Telephony (CTI) webhooks'                              => '#^/api/cti/webhook/#',
		'Health check'                                          => '#^/api/health$#',
		'Prometheus metrics'                                    => '#^/api/metrics$#',
	];

	/**
	 * Whether a path speaks a protocol defined outside the app.
	 *
	 * @param string $path An app-relative path or a request path under the app root.
	 *
	 * @return bool True for a standard protocol path.
	 *
	 * @spec openspec/specs/api-contract/spec.md
	 */
	public static function matches(string $path): bool {
		$relative = self::appRelative(path: $path);
		foreach (self::PATTERNS as $pattern) {
			if (preg_match($pattern, $relative) === 1) {
				return true;
			}
		}

		return false;
	}//end matches()

	/**
	 * The path relative to the app root, leading slash included.
	 *
	 * @param string $path An app-relative path or a request path under the app root.
	 *
	 * @return string The app-relative path.
	 *
	 * @spec openspec/specs/api-contract/spec.md
	 */
	public static function appRelative(string $path): string {
		$candidate = '/'.ltrim($path, '/');
		$rootAt    = strpos($candidate, self::APP_ROOT.'/');
		if ($rootAt === false) {
			return $candidate;
		}

		return substr($candidate, $rootAt + strlen(self::APP_ROOT));
	}//end appRelative()
}//end class
