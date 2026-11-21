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
use OCP\IRequest;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

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
	 * @param array<string, mixed> $body The request parameters.
	 *
	 * @return array<int, mixed> [status, body, events].
	 */
	private function save(array $body): array {
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
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(
			static fn (int $length, string $chars): string => 'random-'.$length.'-'.($chars === ISecureRandom::CHAR_ALPHANUMERIC ? 'alnum' : 'other')
		);
		$response = (new BrpAdminController(request: $request, appConfig: $appConfig, secureRandom: $random))->save();
		return [$response->getStatus(), $response->getData(), $this->events];
	}//end save()

	/**
	 * Every given setting is stored as a string in the order of the settings;
	 * absent ones are left alone, and connection settings are not taken.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/brp-monitor-moves-to-integriq/specs/brp-lookup/spec.md#requirement-brp-person-lookup-goes-through-integriq-only
	 */
	public function testStoresEveryGivenSetting(): void {
		$body = [
			'baseUrl' => 'https://brp.example', 'oauthEndpoint' => 'https://auth.example', 'clientId' => 'pipelinq',
			'clientSecret' => 's3cret', 'certPath' => '/c.pem', 'keyPath' => '/k.pem', 'caBundle' => '/ca.pem',
			'cacheTtlHours' => 12, 'retentionDays' => '3', 'healthTimezone' => 'Europe/Amsterdam', 'allowedGroups' => 'kcc',
		];
		$stored = [
			['set', 'pipelinq', 'brp.cache_ttl_hours', '12'],
			['set', 'pipelinq', 'brp.retention_days', '3'],
			['set', 'pipelinq', 'brp.allowed_groups', 'kcc'],
		];

		$this->assertSame(expected: [200, ['result' => 'ok'], $stored], actual: $this->save(body: $body));
		$this->assertSame(
			expected: [200, ['result' => 'ok'], [['set', 'pipelinq', 'brp.allowed_groups', '']]],
			actual: $this->save(body: ['clientId' => '', 'allowedGroups' => ''])
		);
		$this->assertSame(expected: [200, ['result' => 'ok'], []], actual: $this->save(body: []));
	}//end testStoresEveryGivenSetting()

	/**
	 * The pseudonym secret rotates only on an explicit true, last.
	 *
	 * @return void
	 */
	public function testRotatesThePseudonymSecretOnlyWhenAsked(): void {
		$rotated = ['set', 'pipelinq', 'brp.pseudonym_secret', 'random-64-alnum'];
		$this->assertSame(
			[200, ['result' => 'ok'], [['set', 'pipelinq', 'brp.retention_days', '5'], $rotated]],
			$this->save(body: ['retentionDays' => 5, 'rotatePseudonymSecret' => true])
		);
		$this->assertSame([200, ['result' => 'ok'], [$rotated]], $this->save(body: ['rotatePseudonymSecret' => 'true']));
		foreach ([false, 'false', 1, '1', 'yes'] as $flag) {
			$this->assertSame([200, ['result' => 'ok'], []], $this->save(body: ['rotatePseudonymSecret' => $flag]));
		}
	}//end testRotatesThePseudonymSecretOnlyWhenAsked()
	/**
	 * The controller over the given stored settings.
	 *
	 * @param array<string,string> $stored Stored app-config values by key.
	 * @param IAppConfig|null      $appConfig An app config to use instead.
	 * @param ISecureRandom|null   $random The random source.
	 *
	 * @return BrpAdminController
	 */
	private function controllerOver(array $stored, ?IAppConfig $appConfig = null, ?ISecureRandom $random = null): BrpAdminController {
		if ($appConfig === null) {
			$appConfig = $this->createMock(IAppConfig::class);
		}

		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($stored[$key] ?? $default)
		);

		return new BrpAdminController(
			request: $this->createMock(IRequest::class),
			appConfig: $appConfig,
			secureRandom: ($random ?? $this->createMock(ISecureRandom::class)),
		);
	}//end controllerOver()

	/**
	 * The settings read names integriq for the connection, holds no connection
	 * field, masks the webhook secret and applies the defaults.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/brp-monitor-moves-to-integriq/specs/brp-lookup/spec.md#requirement-pipelinq-does-not-monitor-the-brp-connection
	 */
	public function testGetNamesIntegriqAndAppliesDefaults(): void {
		$data = $this->controllerOver([
			'brp.base_url' => 'https://brp.example',
			'brp.client_id' => 'pipelinq',
			'brp.client_secret_encrypted' => 'cipher',
			'brp.cache_ttl_hours' => '12',
			'brp.webhook_secret' => 'w',
		])->get()->getData();

		self::assertSame(
			expected: [
				'connectionManagedBy' => 'integriq',
				'cacheTtlHours' => 12,
				'retentionDays' => 7,
				'allowedGroups' => '',
				'webhookSecretSet' => true,
			],
			actual: $data
		);
	}//end testGetNamesIntegriqAndAppliesDefaults()

	/**
	 * Rotating the webhook secret stores a fresh 64-character alphanumeric secret and returns it once.
	 *
	 * @return void
	 */
	public function testRotateWebhookSecretStoresAndReturnsTheNewSecret(): void {
		$random = $this->createMock(ISecureRandom::class);
		$random->expects(self::once())->method('generate')->with(64, ISecureRandom::CHAR_ALPHANUMERIC)->willReturn('s3cr3t');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects(self::once())->method('setValueString')->with('pipelinq', 'brp.webhook_secret', 's3cr3t');

		$response = $this->controllerOver([], appConfig: $appConfig, random: $random)->rotateWebhookSecret();

		self::assertSame(200, $response->getStatus());
		self::assertSame(['secret' => 's3cr3t'], $response->getData());
	}//end testRotateWebhookSecretStoresAndReturnsTheNewSecret()

}//end class
