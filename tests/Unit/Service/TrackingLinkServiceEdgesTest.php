<?php

/**
 * Edge cases of TrackingLinkService: token shapes, signing key and TTL config, failing reads and writes.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\AttributionService;
use OCA\Pipelinq\Service\BlastService;
use OCA\Pipelinq\Service\TrackingLinkService;
use OCA\Pipelinq\Service\TrafficEventEmitter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A token is only accepted whole, signed, decodable and unexpired.
 *
 * @covers \OCA\Pipelinq\Service\TrackingLinkService
 * @uses \OCA\Pipelinq\Util\PayloadToArray
 * @uses \OCA\Pipelinq\Util\EntityPayload
 * @uses \OCA\Pipelinq\Util\SerialisedPayload
 */
class TrackingLinkServiceEdgesTest extends TestCase {

	/**
	 * The fixed clock.
	 */
	private const NOW = 1700000000;

	/**
	 * App config values.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * The writes to app config, as [key, value, lazy, sensitive].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $configWrites = [];

	/**
	 * The OpenRegister double.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * Whether the container fails to give the ObjectService.
	 *
	 * @var bool
	 */
	private bool $noObjectService = false;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The secure random mock.
	 *
	 * @var ISecureRandom&MockObject
	 */
	private ISecureRandom $random;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->config = ['blast.tracking_secret' => 'k'];
		$this->objects = new class {
			/** @var array<string, mixed> */
			public array $store = [];

			/** @var array<int, array<string, mixed>> */
			public array $saves = [];

			/** @var bool */
			public bool $failFind = false;

			/** @var bool */
			public bool $failSave = false;

			/**
			 * Find a delivery.
			 *
			 * @param string $id       The id.
			 * @param mixed  $register The register.
			 * @param mixed  $schema   The schema.
			 *
			 * @return mixed
			 */
			public function find(string $id, $register = null, $schema = null): mixed {
				if ($this->failFind === true) {
					throw new RuntimeException('gone');
				}

				return ($this->store[$id] ?? null);
			}

			/**
			 * Save a delivery.
			 *
			 * @param array<string, mixed> $object   The object.
			 * @param mixed                $register The register.
			 * @param mixed                $schema   The schema.
			 * @param string|null          $uuid     The uuid.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, $register = null, $schema = null, ?string $uuid = null): array {
				$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
				if ($this->failSave === true) {
					throw new RuntimeException('refused');
				}

				return $object;
			}
		};
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->random = $this->createMock(ISecureRandom::class);

	}//end setUp()

	/**
	 * The service over the doubles.
	 *
	 * @param BlastService|null $blasts The blast service.
	 *
	 * @return TrackingLinkService
	 */
	private function service(?BlastService $blasts = null): TrackingLinkService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false): bool {
				$this->configWrites[] = [$key, $value, $lazy, $sensitive];
				$this->config[$key] = $value;
				return true;
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				if ($this->noObjectService === true) {
					throw new RuntimeException('no OpenRegister');
				}

				return $this->objects;
			}
		);

		return new TrackingLinkService(
			$container,
			$appConfig,
			$time,
			$this->random,
			$this->createMock(AttributionService::class),
			($blasts ?? $this->createMock(BlastService::class)),
			$this->logger,
			$this->createMock(TrafficEventEmitter::class),
		);

	}//end service()

	/**
	 * A token signed under the configured key, for an arbitrary payload.
	 *
	 * @param string $json The payload JSON.
	 *
	 * @return string
	 */
	private function signed(string $json): string {
		$encoded = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
		$signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $encoded, 'k', true)), '+/', '-_'), '=');
		return $encoded . '.' . $signature;

	}//end signed()

	/**
	 * The payload of a token, decoded without verifying it.
	 *
	 * @param string $token The token.
	 *
	 * @return array<string, mixed>
	 */
	private function payloadOf(string $token): array {
		return json_decode(base64_decode(strtr(explode('.', $token)[0], '-_', '+/')), true);

	}//end payloadOf()

	/**
	 * A token with an empty half, too many parts, or a body that is not the payload is refused.
	 *
	 * @return void
	 */
	public function testMalformedTokensAreRefused(): void {
		$service = $this->service();

		$this->assertNull($service->verifyToken(token: '.sig'));
		$this->assertNull($service->verifyToken(token: 'body.'));
		$this->assertNull($service->verifyToken(token: 'a.b.c'));
		$this->assertNull($service->verifyToken(token: $this->signed(json: '"just a string"')));
		$this->assertNull($service->verifyToken(token: $this->signed(json: '{"exp":1800000000}')));
		$this->assertNull($service->verifyToken(token: $this->signed(json: '{"d":"x"}')));

		$this->assertSame(['d' => 'x', 'exp' => self::NOW], $service->verifyToken(token: $this->signed(json: '{"d":"x","exp":' . self::NOW . '}')));

	}//end testMalformedTokensAreRefused()

	/**
	 * A signed body that is not base64 decodes to nothing and is refused.
	 *
	 * @return void
	 */
	public function testANonBase64BodyIsRefused(): void {
		$encoded = '!!!';
		$signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $encoded, 'k', true)), '+/', '-_'), '=');

		$this->assertNull($this->service()->verifyToken(token: $encoded . '.' . $signature));

	}//end testANonBase64BodyIsRefused()

	/**
	 * Without a configured key one is generated once, stored lazily and as sensitive.
	 *
	 * @return void
	 */
	public function testAMissingSigningKeyIsGeneratedAndStored(): void {
		$this->config = [];
		$this->random->expects($this->once())->method('generate')->with(64, ISecureRandom::CHAR_ALPHANUMERIC)->willReturn('generated-key');
		$service = $this->service();

		$token = $service->signOpenToken(blastDeliveryId: 'd-1');

		$this->assertSame([['blast.tracking_secret', 'generated-key', false, true]], $this->configWrites);
		$this->assertSame('d-1', $service->verifyToken(token: $token)['d']);

	}//end testAMissingSigningKeyIsGeneratedAndStored()

	/**
	 * The TTL comes from config in days; blank, non-numeric and non-positive fall back to 90.
	 *
	 * @return void
	 */
	public function testTheTokenLifetimeComesFromConfig(): void {
		$cases = ['7' => 7, '' => 90, 'soon' => 90, '0' => 90, '-3' => 90];
		foreach ($cases as $raw => $days) {
			$this->config['blast.tracking_token_ttl_days'] = (string)$raw;
			$payload = $this->payloadOf(token: $this->service()->signClickToken(blastDeliveryId: 'd', targetUrl: 'https://x.test'));

			$this->assertSame(self::NOW, $payload['iat'], (string)$raw);
			$this->assertSame(self::NOW + ($days * 86400), $payload['exp'], (string)$raw);
			$this->assertSame('https://x.test', $payload['u']);
		}

	}//end testTheTokenLifetimeComesFromConfig()

	/**
	 * Fragment links and template tokens are not rewritten; a page without body gets the pixel appended.
	 *
	 * @return void
	 */
	public function testFragmentsAndTemplateLinksStay(): void {
		$html = '<a href="#top">Top</a><a href=\'{{unsubscribe_link}}\'>Off</a><a href="">Empty</a>';

		$out = $this->service()->injectTracking(html: $html, blastDeliveryId: 'd-1');

		$this->assertStringStartsWith($html . '<img src="/api/blast/track/open/', $out);
		$this->assertStringEndsWith('style="display:none;border:0;width:1px;height:1px;" />', $out);

	}//end testFragmentsAndTemplateLinksStay()

	/**
	 * The pixel goes before the closing body tag, whatever its case.
	 *
	 * @return void
	 */
	public function testThePixelGoesBeforeTheBodyEnd(): void {
		$out = $this->service()->injectTracking(html: '<html><BODY>Hi</BODY></html>', blastDeliveryId: 'd-1');

		$this->assertMatchesRegularExpression('#^<html><BODY>Hi<img src="/api/blast/track/open/[^"]+"[^>]*/></body></html>$#', $out);

	}//end testThePixelGoesBeforeTheBodyEnd()

	/**
	 * An open under configured slugs is saved under the @self id; a non-openable status stays.
	 *
	 * @return void
	 */
	public function testAnOpenIsSavedUnderTheSelfId(): void {
		$this->config += ['register' => 'crm', 'blastDelivery_schema' => 'delivery'];
		$this->objects->store['d-1'] = ['@self' => ['id' => 'self-1'], 'status' => 'bounced', 'openedAt' => '2026-01-01T00:00:00Z'];
		$blasts = $this->createMock(BlastService::class);
		$blasts->expects($this->never())->method('updateBlastTotals');

		$this->service(blasts: $blasts)->recordOpen(blastDeliveryId: 'd-1');

		$this->assertCount(1, $this->objects->saves);
		$save = $this->objects->saves[0];
		$this->assertSame(['crm', 'delivery', 'self-1'], [$save['register'], $save['schema'], $save['uuid']]);
		$this->assertSame('bounced', $save['object']['status']);
		$this->assertSame('2026-01-01T00:00:00Z', $save['object']['openedAt']);

	}//end testAnOpenIsSavedUnderTheSelfId()

	/**
	 * A delivery without any id is not saved.
	 *
	 * @return void
	 */
	public function testADeliveryWithoutAnIdIsNotSaved(): void {
		$this->objects->store['d-1'] = ['status' => 'sent'];

		$this->service()->recordOpen(blastDeliveryId: 'd-1');

		$this->assertSame([], $this->objects->saves);

	}//end testADeliveryWithoutAnIdIsNotSaved()

	/**
	 * A failing read and a failing save are logged, not thrown.
	 *
	 * @return void
	 */
	public function testFailingReadsAndSavesAreLogged(): void {
		$this->objects->failFind = true;
		$this->logger->expects($this->once())->method('info')->with('TrackingLinkService.loadDelivery: not found', ['id' => 'd-1', 'exception' => 'gone']);
		$this->service()->recordOpen(blastDeliveryId: 'd-1');
		$this->assertSame([], $this->objects->saves);

		$this->objects->failFind = false;
		$this->objects->failSave = true;
		$this->objects->store['d-2'] = ['uuid' => 'd-2', 'status' => 'sent'];
		$this->logger->expects($this->once())->method('warning')->with('TrackingLinkService.saveDelivery: save failed', ['id' => 'd-2', 'exception' => 'refused']);
		$this->service()->recordOpen(blastDeliveryId: 'd-2');
		$this->assertSame('opened', $this->objects->saves[0]['object']['status']);

	}//end testFailingReadsAndSavesAreLogged()

	/**
	 * Without OpenRegister, or with an empty id, nothing is read.
	 *
	 * @return void
	 */
	public function testNothingIsReadWithoutOpenRegisterOrAnId(): void {
		$this->objects->store[''] = ['uuid' => 'x', 'status' => 'sent'];
		$this->service()->recordOpen(blastDeliveryId: '');
		$this->assertSame([], $this->objects->saves);

		$this->noObjectService = true;
		$this->objects->store['d-1'] = ['uuid' => 'd-1', 'status' => 'sent'];
		$this->logger->expects($this->once())->method('warning')->with('TrackingLinkService.getObjectService: OpenRegister unavailable', ['exception' => 'no OpenRegister']);
		$this->service()->recordClick(blastDeliveryId: 'd-1', url: 'https://x.test');
		$this->assertSame([], $this->objects->saves);

	}//end testNothingIsReadWithoutOpenRegisterOrAnId()
}//end class
