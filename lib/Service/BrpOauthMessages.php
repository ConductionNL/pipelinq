<?php

/**
 * Pipelinq BrpOauthMessages.
 *
 * The OAuth2 client_credentials request and response of the BRP integration.
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
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\Http\Client\IResponse;

/**
 * Builds the OAuth2 token request of the HaalCentraal BRP API and reads the
 * access token out of its response.
 *
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-003
 */
final class BrpOauthMessages {
	/**
	 * The headers of the OAuth2 token request.
	 *
	 * @var array<string, string>
	 */
	private const REQUEST_HEADERS = [
		'Accept' => 'application/json',
		'Content-Type' => 'application/x-www-form-urlencoded',
	];

	/**
	 * Token request timeout (5s per REQ-BSN-003-01).
	 */
	private const TIMEOUT_SECONDS = 5;

	/**
	 * The form body of a client_credentials token request.
	 *
	 * @param string $clientId     The client id.
	 * @param string $clientSecret The client secret.
	 *
	 * @return string The url-encoded body.
	 *
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-003
	 */
	public function requestBody(
		string $clientId,
		#[\SensitiveParameter]
		string $clientSecret,
	): string {
		return http_build_query(
			[
				'grant_type' => 'client_credentials',
				'client_id' => $clientId,
				'client_secret' => $clientSecret,
				'scope' => 'haalcentraal.brp.personen',
			]
		);
	}//end requestBody()
	/**
	 * The request options of the token request.
	 *
	 * @param string $body The url-encoded token request body.
	 *
	 * @return array<string, mixed> The options.
	 *
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-003
	 */
	public function requestOptions(
		#[\SensitiveParameter]
		string $body,
	): array {
		return [
			'headers' => self::REQUEST_HEADERS,
			'body' => $body,
			'timeout' => self::TIMEOUT_SECONDS,
			'connect_timeout' => 2,
		];
	}//end requestOptions()
	/**
	 * The access token of a token response.
	 *
	 * @param IResponse $response The token response.
	 *
	 * @return string The access token.
	 *
	 * @throws HaalCentraalException When the status is outside 2xx or the token is missing.
	 *
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-003
	 */
	public function tokenFrom(IResponse $response): string {
		$status = (int)$response->getStatusCode();
		$payload = json_decode((string)$response->getBody(), true);
		$token = '';
		if (is_array($payload) === true) {
			$token = (string)($payload['access_token'] ?? '');
		}

		if ($status < 200 || $status >= 300 || $token === '') {
			throw new HaalCentraalException(
				'BRP OAuth2-token aanvraag is mislukt.',
				$status,
			);
		}

		return $token;
	}//end tokenFrom()
}//end class
