<?php

/**
 * Unit tests for SocialBrokerGateway.
 *
 * The test that matters most is `testARelinkExceptionIsCaughtBeforeItsParent`.
 * `CredentialRelinkRequiredException` extends `CredentialAccessDeniedException`
 * in OpenRegister, so a `classify()` that asked about the parent first would
 * compile, pass review, and report every dead grant as a permission problem,
 * which is the one diagnosis a person cannot act on. The stub in
 * `tests/Stubs/Service/Credential/` reproduces that inheritance on purpose, so
 * a wrong catch order fails here rather than in production.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Social
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Social;

use OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\CredentialRelinkRequiredException;
use OCA\OpenRegister\Service\Credential\ProviderCatalogue;
use OCA\Pipelinq\Service\Social\SocialBrokerGateway;
use OCA\Pipelinq\Service\Social\SocialGatewayResult;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The brokered egress seam: its failure mapping, its catch order and its
 * readiness answers.
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
 */
class SocialBrokerGatewayTest extends TestCase {
	/**
	 * A gateway whose container answers with the given broker and catalogue.
	 *
	 * @param object|null $broker The broker double, or null to fail the resolve.
	 * @param object|null $catalogue The catalogue double, or null to fail the resolve.
	 *
	 * @return SocialBrokerGateway The gateway under test.
	 */
	private function gateway(?object $broker = null, ?object $catalogue = null): SocialBrokerGateway {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($broker, $catalogue): object {
				if ($id === SocialBrokerGateway::BROKER_CLASS && $broker !== null) {
					return $broker;
				}

				if ($id === SocialBrokerGateway::CATALOGUE_CLASS && $catalogue !== null) {
					return $catalogue;
				}

				throw new RuntimeException(message: 'not bound: ' . $id);
			}
		);

		return new SocialBrokerGateway($container, $this->createMock(LoggerInterface::class));
	}

	/**
	 * A dead grant is reported as `relink_needed`, not as a permission
	 * refusal, even though its exception IS an access exception.
	 *
	 * @return void
	 */
	public function testARelinkExceptionIsCaughtBeforeItsParent(): void {
		$this->assertTrue(
			is_subclass_of(CredentialRelinkRequiredException::class, CredentialAccessDeniedException::class),
			'the relink exception must extend the access exception, or this test proves nothing',
		);

		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('request')->willThrowException(new CredentialRelinkRequiredException());

		$result = $this->gateway(broker: $broker)->request(
			credentialRef: 'cred-1',
			method: 'POST',
			path: '/api/v1/statuses',
		);

		$this->assertFalse($result->accepted);
		$this->assertSame(SocialGatewayResult::RELINK_NEEDED, $result->failureCode);
	}

	/**
	 * A plain access refusal stays a permission problem.
	 *
	 * @return void
	 */
	public function testAnAccessRefusalIsReportedAsNotPermitted(): void {
		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('request')->willThrowException(new CredentialAccessDeniedException());

		$result = $this->gateway(broker: $broker)->request(
			credentialRef: 'cred-1',
			method: 'POST',
			path: '/api/v1/statuses',
		);

		$this->assertSame(SocialGatewayResult::NOT_PERMITTED, $result->failureCode);
	}

	/**
	 * An account with no credential never reaches the broker at all.
	 *
	 * @return void
	 */
	public function testAnAccountWithNoCredentialIsNotConfigured(): void {
		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->expects($this->never())->method('request');

		$result = $this->gateway(broker: $broker)->request(
			credentialRef: '  ',
			method: 'POST',
			path: '/api/v1/statuses',
		);

		$this->assertSame(SocialGatewayResult::NOT_CONFIGURED, $result->failureCode);
	}

	/**
	 * A 4xx from the network is the network's own refusal, and the reason
	 * carries the network's words rather than a status code alone.
	 *
	 * @return void
	 */
	public function testAFourHundredIsTheNetworksOwnRefusal(): void {
		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('request')->willReturn([
			'status' => 422,
			'headers' => [],
			'body' => '{"error":"Text character limit exceeded"}',
		]);

		$result = $this->gateway(broker: $broker)->request(
			credentialRef: 'cred-1',
			method: 'POST',
			path: '/2/tweets',
		);

		$this->assertSame(SocialGatewayResult::REJECTED_BY_NETWORK, $result->failureCode);
		$this->assertStringContainsString('Text character limit exceeded', $result->failureReason);
		$this->assertTrue($result->isRetryable());
	}

	/**
	 * A 401 means the grant no longer carries what it did, which a retry
	 * cannot mend and a reconnect can.
	 *
	 * @return void
	 */
	public function testAnUnauthorisedAnswerAsksForAReconnect(): void {
		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('request')->willReturn(['status' => 401, 'headers' => [], 'body' => '{}']);

		$result = $this->gateway(broker: $broker)->request(
			credentialRef: 'cred-1',
			method: 'POST',
			path: '/2/tweets',
		);

		$this->assertSame(SocialGatewayResult::RELINK_NEEDED, $result->failureCode);
		$this->assertFalse($result->isRetryable());
	}

	/**
	 * A 5xx can be tried again.
	 *
	 * @return void
	 */
	public function testAServerErrorIsRetryable(): void {
		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('request')->willReturn(['status' => 503, 'headers' => [], 'body' => '']);

		$result = $this->gateway(broker: $broker)->request(
			credentialRef: 'cred-1',
			method: 'POST',
			path: '/2/tweets',
		);

		$this->assertSame(SocialGatewayResult::UNAVAILABLE, $result->failureCode);
		$this->assertTrue($result->isRetryable());
	}

	/**
	 * A 2xx is a success carrying the decoded body.
	 *
	 * @return void
	 */
	public function testASuccessCarriesTheDecodedBody(): void {
		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('request')->willReturn([
			'status' => 200,
			'headers' => [],
			'body' => '{"id":"109","url":"https://mastodon.nl/@conduction/109"}',
		]);

		$result = $this->gateway(broker: $broker)->request(
			credentialRef: 'cred-1',
			method: 'POST',
			path: '/api/v1/statuses',
		);

		$this->assertTrue($result->accepted);
		$this->assertSame('109', $result->body['id']);
	}

	/**
	 * The authorization header an adapter set is DROPPED before the broker
	 * sees it. The broker discards a caller-supplied one anyway; dropping it
	 * here means a stale header can never look like it is doing something.
	 *
	 * @return void
	 */
	public function testACallerSuppliedAuthorizationHeaderNeverReachesTheBroker(): void {
		$seen = [];
		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('request')->willReturnCallback(
			static function (
				string $credentialId,
				string $appId,
				string $method,
				string $path,
				array $headers = [],
				?string $body = null,
				?string $actingUserId = null,
			) use (&$seen): array {
				$seen = $headers;

				return ['status' => 200, 'headers' => [], 'body' => '{}'];
			}
		);

		$this->gateway(broker: $broker)->request(
			credentialRef: 'cred-1',
			method: 'POST',
			path: '/api/v1/statuses',
			headers: ['Authorization' => 'Bearer YOUR_TOKEN_HERE', 'Content-Type' => 'application/json'],
		);

		$this->assertArrayNotHasKey('Authorization', $seen);
		$this->assertSame('application/json', $seen['Content-Type']);
	}

	/**
	 * A network with no catalogue entry has no developer application filed,
	 * and says so.
	 *
	 * @return void
	 */
	public function testANetworkWithNoCatalogueEntryIsNotConfigured(): void {
		$catalogue = $this->createMock(ProviderCatalogue::class);
		$catalogue->method('get')->willReturn(null);

		$readiness = $this->gateway(catalogue: $catalogue)->readiness(brokerProvider: 'threads');

		$this->assertSame(SocialBrokerGateway::NOT_CONFIGURED, $readiness['state']);
		$this->assertNotSame('', $readiness['reason']);
	}

	/**
	 * An adapter that names no provider at all is not configured, without the
	 * catalogue being consulted.
	 *
	 * @return void
	 */
	public function testAnAdapterWithNoProviderIsNotConfigured(): void {
		$catalogue = $this->createMock(ProviderCatalogue::class);
		$catalogue->expects($this->never())->method('get');

		$readiness = $this->gateway(catalogue: $catalogue)->readiness(brokerProvider: '');

		$this->assertSame(SocialBrokerGateway::NOT_CONFIGURED, $readiness['state']);
	}

	/**
	 * A provider the broker ships as a preview is reported as such and is NOT
	 * blocked: nothing is missing on the Pipelinq side.
	 *
	 * @return void
	 */
	public function testAPreviewProviderIsReportedAndNotBlocked(): void {
		$catalogue = $this->createMock(ProviderCatalogue::class);
		$catalogue->method('get')->willReturn(['identifier' => 'bluesky', 'preview' => true]);

		$readiness = $this->gateway(catalogue: $catalogue)->readiness(brokerProvider: 'bluesky');

		$this->assertSame(SocialBrokerGateway::PREVIEW, $readiness['state']);
		$this->assertNotSame(SocialBrokerGateway::NOT_CONFIGURED, $readiness['state']);
	}

	/**
	 * A filed provider is ready.
	 *
	 * @return void
	 */
	public function testAFiledProviderIsReady(): void {
		$catalogue = $this->createMock(ProviderCatalogue::class);
		$catalogue->method('get')->willReturn(['identifier' => 'mastodon']);

		$readiness = $this->gateway(catalogue: $catalogue)->readiness(brokerProvider: 'mastodon');

		$this->assertSame(SocialBrokerGateway::READY, $readiness['state']);
		$this->assertSame('', $readiness['reason']);
	}
}//end class
