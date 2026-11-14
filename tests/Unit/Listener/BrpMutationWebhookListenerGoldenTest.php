<?php

/**
 * Golden test for the BRP mutation webhook listener's accept / refuse outcomes.
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
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-004-03
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\Pipelinq\Listener\BrpMutationWebhookListener;
use OCA\Pipelinq\Service\BrpCacheService;
use OCA\Pipelinq\Service\BsnAudit\LookupAudit;
use OCA\Pipelinq\Service\BsnAuditService;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins every outcome of handle(): result, invalidated count, cache key, audit and log lines.
 */
class BrpMutationWebhookListenerGoldenTest extends TestCase {
	private const SECRET = 'golden-secret';

	/**
	 * Every delivery shape and its exact outcome.
	 *
	 * @return array<string, array{string, string, ?string, string, int, ?string, list<string>}>
	 */
	public static function deliveries(): array {
		$ok = '{"burgerservicenummer":"123456782"}';
		return [
			'no secret configured' => ['', $ok, null, 'forbidden', 0, null, ['warning:BRP webhook secret not configured; dropping delivery']],
			'wrong signature' => [self::SECRET, $ok, 'deadbeef', 'forbidden', 0, null, ['warning:BRP webhook signature mismatch']],
			'signature of another secret' => [self::SECRET, $ok, hash_hmac('sha256', $ok, 'other'), 'forbidden', 0, null, ['warning:BRP webhook signature mismatch']],
			'invalid json' => [self::SECRET, '{not json', null, 'bad-request', 0, null, ['info:BRP webhook payload not valid JSON']],
			'too deep json' => [self::SECRET, str_repeat('[', 40).str_repeat(']', 40), null, 'bad-request', 0, null, ['info:BRP webhook payload not valid JSON']],
			'scalar json' => [self::SECRET, '5', null, 'bad-request', 0, null, []],
			'no bsn' => [self::SECRET, '{"foo":"bar"}', null, 'bad-request', 0, null, []],
			'short bsn' => [self::SECRET, '{"bsn":"12345678"}', null, 'bad-request', 0, null, []],
			'non-digit bsn' => [self::SECRET, '{"bsn":"12345678a"}', null, 'bad-request', 0, null, []],
			'bsn field' => [self::SECRET, '{"bsn":"111222333"}', null, 'ok', 3, '111222333', ['info:BRP webhook invalidated cache']],
			'numeric bsn' => [self::SECRET, '{"bsn":111222333}', null, 'ok', 3, '111222333', ['info:BRP webhook invalidated cache']],
			'burgerservicenummer wins' => [self::SECRET, '{"bsn":"111222333","burgerservicenummer":"123456782"}', null, 'ok', 3, '123456782', ['info:BRP webhook invalidated cache']],
		];
	}//end deliveries()

	/**
	 * One delivery's outcome matches exactly.
	 *
	 * @param string       $secret      Configured secret.
	 * @param string       $body        Raw body.
	 * @param string|null  $signature   Signature, null for a correct one.
	 * @param string       $result      Expected result code.
	 * @param int          $invalidated Expected count.
	 * @param string|null  $cacheBsn    Expected invalidated BSN, null for none.
	 * @param list<string> $logs        Expected log lines.
	 *
	 * @return void
	 */
	#[DataProvider('deliveries')]
	public function testDeliveryOutcome(
		string $secret,
		string $body,
		?string $signature,
		string $result,
		int $invalidated,
		?string $cacheBsn,
		array $logs,
	): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') => $key === 'brp.webhook_secret' ? $secret : 'unexpected'
		);
		$cache = $this->createMock(BrpCacheService::class);
		$cache->expects($cacheBsn === null ? self::never() : self::once())->method('invalidate')
			->with($cacheBsn ?? '')->willReturn(3);
		$audits = [];
		$audit = $this->createMock(BsnAuditService::class);
		$audit->method('recordLookup')->willReturnCallback(
			static function (string $actor, LookupAudit $lookup) use (&$audits): string {
				$audits[] = [$actor, $lookup->subject->rawBsn, $lookup->result->outcome, $lookup->result->action, $lookup->actorRole];
				return 'audit-id';
			}
		);
		$seen = [];
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(
				static function (string $message) use (&$seen, $level): void {
					$seen[] = $level.':'.$message;
				}
			);
		}

		$listener = new BrpMutationWebhookListener($config, $cache, $audit, $logger);
		$out = $listener->handle($body, $signature ?? hash_hmac('sha256', $body, $secret));

		self::assertSame(['result' => $result, 'invalidated' => $invalidated], $out);
		self::assertSame($logs, $seen);
		$expectedAudits = $cacheBsn === null ? [] : [['system:brp-webhook', $cacheBsn, 'cache-invalidated', 'brp-cache-invalidated', 'system']];
		self::assertSame($expectedAudits, $audits);
	}//end testDeliveryOutcome()
}//end class
