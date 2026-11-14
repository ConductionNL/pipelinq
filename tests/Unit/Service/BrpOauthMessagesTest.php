<?php

/**
 * Unit tests for BrpOauthMessages.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\BrpOauthMessages;
use OCA\Pipelinq\Service\HaalCentraalException;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * BrpOauthMessages builds the BRP client-credentials request and reads the token.
 */
class BrpOauthMessagesTest extends TestCase {

	/**
	 * A token response.
	 *
	 * @param int    $status The HTTP status.
	 * @param string $body   The body.
	 *
	 * @return IResponse
	 */
	private function response(int $status, string $body): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);

		return $response;
	}//end response()

	/**
	 * The request body is a client-credentials grant for the BRP persons scope.
	 *
	 * @return void
	 */
	public function testTheRequestBody(): void {
		$body = (new BrpOauthMessages())->requestBody(clientId: 'gemeente app', clientSecret: 's3cr&t=');

		parse_str($body, $fields);
		self::assertSame(
			['grant_type' => 'client_credentials', 'client_id' => 'gemeente app', 'client_secret' => 's3cr&t=', 'scope' => 'haalcentraal.brp.personen'],
			$fields
		);
		self::assertStringContainsString('client_secret=s3cr%26t%3D', $body);
	}//end testTheRequestBody()

	/**
	 * The request posts a form, asks for JSON and gives up quickly.
	 *
	 * @return void
	 */
	public function testTheRequestOptions(): void {
		self::assertSame(
			[
				'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/x-www-form-urlencoded'],
				'body' => 'a=b',
				'timeout' => 5,
				'connect_timeout' => 2,
			],
			(new BrpOauthMessages())->requestOptions(body: 'a=b')
		);
	}//end testTheRequestOptions()

	/**
	 * A 2xx response with an access token yields the token.
	 *
	 * @return void
	 */
	public function testATokenIsRead(): void {
		self::assertSame('tok-123', (new BrpOauthMessages())->tokenFrom(response: $this->response(200, '{"access_token":"tok-123","expires_in":300}')));
	}//end testATokenIsRead()

	/**
	 * Every response without a usable token fails with the status as code.
	 *
	 * @return array<string, array{0: int, 1: string}>
	 */
	public static function failures(): array {
		return [
			'unauthorised' => [401, '{"error":"invalid_client"}'],
			'server error with a token' => [500, '{"access_token":"tok"}'],
			'redirect' => [302, '{"access_token":"tok"}'],
			'no token' => [200, '{"token_type":"Bearer"}'],
			'empty token' => [200, '{"access_token":""}'],
			'not json' => [200, 'access_token=tok'],
		];
	}//end failures()

	/**
	 * A failed token request is refused.
	 *
	 * @param int    $status The HTTP status.
	 * @param string $body   The body.
	 *
	 * @return void
	 */
	#[DataProvider('failures')]
	public function testAFailedTokenRequestIsRefused(int $status, string $body): void {
		try {
			(new BrpOauthMessages())->tokenFrom(response: $this->response($status, $body));
			self::fail('a token was returned');
		} catch (HaalCentraalException $e) {
			self::assertSame('BRP OAuth2-token aanvraag is mislukt.', $e->getMessage());
			self::assertSame($status, $e->getCode());
		}
	}//end testAFailedTokenRequestIsRefused()
}//end class
