<?php

/**
 * Unit tests for BrpMutationWebhookListener.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#9.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\Pipelinq\Listener\BrpMutationWebhookListener;
use OCA\Pipelinq\Service\BrpCacheService;
use OCA\Pipelinq\Service\BsnAuditService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Verifies the HMAC + payload + cache-invalidation flow of the webhook listener.
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-004-03
 */
class BrpMutationWebhookListenerTest extends TestCase {
	/**
	 * Listener under test.
	 *
	 * @var BrpMutationWebhookListener
	 */
	private BrpMutationWebhookListener $listener;

	/**
	 * Mock app config.
	 *
	 * @var IAppConfig
	 */
	private IAppConfig $appConfig;

	/**
	 * Mock cache service.
	 *
	 * @var BrpCacheService
	 */
	private BrpCacheService $cache;

	/**
	 * Mock audit service.
	 *
	 * @var BsnAuditService
	 */
	private BsnAuditService $audit;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->cache = $this->createMock(BrpCacheService::class);
		$this->audit = $this->createMock(BsnAuditService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->listener = new BrpMutationWebhookListener(
			$this->appConfig,
			$this->cache,
			$this->audit,
			$this->logger,
		);
	}//end setUp()

	/**
	 * Missing secret = forbidden, no cache call.
	 *
	 * @return void
	 */
	public function testMissingSecretReturnsForbidden(): void {
		$this->appConfig->method('getValueString')->willReturn('');
		$this->cache->expects(self::never())->method('invalidate');

		$out = $this->listener->handle('{}', 'whatever');
		self::assertSame(BrpMutationWebhookListener::RESULT_FORBIDDEN, $out['result']);
	}//end testMissingSecretReturnsForbidden()

	/**
	 * Wrong signature = forbidden.
	 *
	 * @return void
	 */
	public function testWrongSignatureReturnsForbidden(): void {
		$this->appConfig
			->method('getValueString')
			->willReturnCallback(static function (string $app, string $key, string $default = '') {
				return $key === 'brp.webhook_secret' ? 'my-test-secret' : $default;
			});

		$this->cache->expects(self::never())->method('invalidate');

		$body = '{"burgerservicenummer":"123456782"}';
		$out = $this->listener->handle($body, 'deadbeef');
		self::assertSame(BrpMutationWebhookListener::RESULT_FORBIDDEN, $out['result']);
	}//end testWrongSignatureReturnsForbidden()

	/**
	 * Correct HMAC + valid BSN = ok + cache invalidated + audit written.
	 *
	 * @return void
	 */
	public function testCorrectSignatureInvalidatesCacheAndAudits(): void {
		$secret = 'unit-test-secret';
		$body = '{"burgerservicenummer":"123456782"}';
		$sig = hash_hmac('sha256', $body, $secret);

		$this->appConfig
			->method('getValueString')
			->willReturnCallback(static function (string $app, string $key, string $default = '') use ($secret) {
				return $key === 'brp.webhook_secret' ? $secret : $default;
			});

		$this->cache->expects(self::once())->method('invalidate')->with('123456782')->willReturn(2);
		$this->audit->expects(self::once())->method('recordLookup');

		$out = $this->listener->handle($body, $sig);
		self::assertSame(BrpMutationWebhookListener::RESULT_OK, $out['result']);
		self::assertSame(2, $out['invalidated']);
	}//end testCorrectSignatureInvalidatesCacheAndAudits()

	/**
	 * Valid signature but missing BSN = bad-request.
	 *
	 * @return void
	 */
	public function testMissingBsnReturnsBadRequest(): void {
		$secret = 's3cret';
		$body = '{"foo":"bar"}';
		$sig = hash_hmac('sha256', $body, $secret);

		$this->appConfig
			->method('getValueString')
			->willReturnCallback(static function (string $app, string $key, string $default = '') use ($secret) {
				return $key === 'brp.webhook_secret' ? $secret : $default;
			});

		$this->cache->expects(self::never())->method('invalidate');
		$out = $this->listener->handle($body, $sig);
		self::assertSame(BrpMutationWebhookListener::RESULT_BAD_REQUEST, $out['result']);
	}//end testMissingBsnReturnsBadRequest()
}//end class
