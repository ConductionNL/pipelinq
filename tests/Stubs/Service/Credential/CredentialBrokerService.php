<?php

/**
 * Test stub for OCA\OpenRegister\Service\Credential\CredentialBrokerService.
 *
 * Only the surface `SocialBrokerGateway` touches: `request()`, and the two
 * constants that name the broker's own register and schema. The body is inert
 * because every test hands the gateway a double of this class.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Stubs\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Credential;

/**
 * The credential broker, as much of it as Pipelinq calls.
 */
class CredentialBrokerService {
	/**
	 * The broker's own register.
	 *
	 * @var string
	 */
	public const REGISTER = 'credential-broker';

	/**
	 * The broker's own schema.
	 *
	 * @var string
	 */
	public const SCHEMA = 'brokeredcredential';

	/**
	 * Proxy one call to a provider.
	 *
	 * @param string $credentialId The credential UUID.
	 * @param string $appId The calling app id.
	 * @param string $method The HTTP method.
	 * @param string $path The provider-relative path.
	 * @param array<string, string> $headers Extra headers.
	 * @param string|null $body The raw body.
	 * @param string|null $actingUserId The asserted user, on the sessionless path.
	 *
	 * @return array{status: int, headers: array<string, mixed>, body: string} The upstream answer.
	 */
	public function request(
		string $credentialId,
		string $appId,
		string $method,
		string $path,
		array $headers = [],
		?string $body = null,
		?string $actingUserId = null,
	): array {
		return ['status' => 0, 'headers' => [], 'body' => ''];
	}//end request()
}//end class
