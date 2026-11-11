<?php

/**
 * Unit tests for BrpAdminController::save.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\BrpAdminController;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins every config write, log line and answer of saving the BRP settings.
 */
final class BrpAdminControllerTest extends TestCase {
	/**
	 * Every config write and log line, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $events = [];

	/**
	 * Save the settings with the given body.
	 *
	 * @param array<string, mixed> $body          The request parameters.
	 * @param bool                 $cryptoFails   Whether encrypting the client secret fails.
	 *
	 * @return array<int, mixed> [status, body, events].
	 */
	private function save(array $body, bool $cryptoFails = false): array {
		$this->events = [];
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => (array_key_exists($key, $body) === true ? $body[$key] : $default)
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->events[] = ['set', $app, $key, $value];
				return true;
			}
		);
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(
			static function (string $plain) use ($cryptoFails): string {
				if ($cryptoFails === true) {
					throw new RuntimeException('no key');
				}

				return 'enc('.$plain.')';
			}
		);
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(
			static fn (int $length, string $chars): string => 'random-'.$length.'-'.($chars === ISecureRandom::CHAR_ALPHANUMERIC ? 'alnum' : 'other')
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->events[] = ['error', $message, $context];
			}
		);

		$response = (new BrpAdminController($request, $appConfig, $crypto, $random, $l10n, $logger))->save();
		return [$response->getStatus(), $response->getData(), $this->events];
	}//end save()

	/**
	 * Every given setting is stored as a string in the order of the settings; absent ones are left alone.
	 *
	 * @return void
	 */
	public function testStoresEveryGivenSetting(): void {
		$body = [
			'baseUrl' => 'https://brp.example', 'oauthEndpoint' => 'https://auth.example', 'clientId' => 'pipelinq',
			'certPath' => '/c.pem', 'keyPath' => '/k.pem', 'caBundle' => '/ca.pem', 'cacheTtlHours' => 12,
			'retentionDays' => '3', 'healthTimezone' => 'Europe/Amsterdam', 'allowedGroups' => 'kcc',
		];
		$stored = [];
		foreach (['base_url' => 'https://brp.example', 'oauth_endpoint' => 'https://auth.example', 'client_id' => 'pipelinq', 'cert_path' => '/c.pem', 'key_path' => '/k.pem', 'ca_bundle' => '/ca.pem', 'cache_ttl_hours' => '12', 'retention_days' => '3', 'health_timezone' => 'Europe/Amsterdam', 'allowed_groups' => 'kcc'] as $key => $value) {
			$stored[] = ['set', 'pipelinq', 'brp.'.$key, $value];
		}

		$this->assertSame([200, ['result' => 'ok'], $stored], $this->save(body: $body));
		$this->assertSame(
			[200, ['result' => 'ok'], [['set', 'pipelinq', 'brp.client_id', ''], ['set', 'pipelinq', 'brp.allowed_groups', '']]],
			$this->save(body: ['clientId' => '', 'allowedGroups' => ''])
		);
		$this->assertSame([200, ['result' => 'ok'], []], $this->save(body: []));
	}//end testStoresEveryGivenSetting()

	/**
	 * A non-empty client secret is stored encrypted, after the settings; an empty or non-string one keeps the stored secret.
	 *
	 * @return void
	 */
	public function testEncryptsANewClientSecret(): void {
		$this->assertSame(
			[200, ['result' => 'ok'], [['set', 'pipelinq', 'brp.base_url', 'https://brp.example'], ['set', 'pipelinq', 'brp.client_secret_encrypted', 'enc(s3cret)']]],
			$this->save(body: ['baseUrl' => 'https://brp.example', 'clientSecret' => 's3cret'])
		);
		$this->assertSame([200, ['result' => 'ok'], []], $this->save(body: ['clientSecret' => '']));
		$this->assertSame([200, ['result' => 'ok'], []], $this->save(body: ['clientSecret' => 42]));
	}//end testEncryptsANewClientSecret()

	/**
	 * A secret that cannot be encrypted is never stored, is logged, and stops the save before the pseudonym rotation.
	 *
	 * @return void
	 */
	public function testAFailingEncryptionStopsTheSave(): void {
		$this->assertSame(
			[
				500,
				['errorCode' => 'crypto-failed', 'errorMessage' => 'Kon BRP client-secret niet versleutelen.'],
				[
					['set', 'pipelinq', 'brp.client_id', 'pipelinq'],
					['error', 'Failed to encrypt BRP client secret', ['error' => 'no key']],
				],
			],
			$this->save(body: ['clientId' => 'pipelinq', 'clientSecret' => 's3cret', 'rotatePseudonymSecret' => true], cryptoFails: true)
		);
	}//end testAFailingEncryptionStopsTheSave()

	/**
	 * The pseudonym secret rotates only on an explicit true, last.
	 *
	 * @return void
	 */
	public function testRotatesThePseudonymSecretOnlyWhenAsked(): void {
		$rotated = ['set', 'pipelinq', 'brp.pseudonym_secret', 'random-64-alnum'];
		$this->assertSame(
			[200, ['result' => 'ok'], [['set', 'pipelinq', 'brp.client_secret_encrypted', 'enc(s)'], $rotated]],
			$this->save(body: ['clientSecret' => 's', 'rotatePseudonymSecret' => true])
		);
		$this->assertSame([200, ['result' => 'ok'], [$rotated]], $this->save(body: ['rotatePseudonymSecret' => 'true']));
		foreach ([false, 'false', 1, '1', 'yes'] as $flag) {
			$this->assertSame([200, ['result' => 'ok'], []], $this->save(body: ['rotatePseudonymSecret' => $flag]));
		}
	}//end testRotatesThePseudonymSecretOnlyWhenAsked()
}//end class
