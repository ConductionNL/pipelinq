<?php

/**
 * Pipelinq BrpMutationWebhookListener.
 *
 * Handler for the inbound HaalCentraal mutation webhook. Verifies the HMAC-SHA256
 * signature, extracts the BSN from the payload, and invalidates the BrpCacheService
 * entries for that BSN.
 *
 * @category Listener
 * @package  OCA\Pipelinq\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.6
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\BrpCacheService;
use OCA\Pipelinq\Service\BsnAuditService;
use OCA\Pipelinq\Service\BsnValidationService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * HMAC-verifying BRP mutation webhook handler.
 *
 * The controller calls {@see handle()} after capturing the raw body + signature
 * header. The listener owns:
 *   1. Constant-time HMAC verification (hash_equals).
 *   2. BSN extraction from the payload.
 *   3. Cache invalidation through BrpCacheService.
 *   4. Audit-record write through BsnAuditService.
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-004-03
 */
class BrpMutationWebhookListener {
	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App config (webhook secret).
	 * @param BrpCacheService $cacheService Cache service.
	 * @param BsnAuditService $auditService Audit service.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private BrpCacheService $cacheService,
		private BsnAuditService $auditService,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Result codes for handle().
	 */
	public const RESULT_OK = 'ok';

	/**
	 * Result code: signature mismatch.
	 */
	public const RESULT_FORBIDDEN = 'forbidden';

	/**
	 * Result code: payload was malformed / missing BSN.
	 */
	public const RESULT_BAD_REQUEST = 'bad-request';

	/**
	 * Process a single webhook delivery.
	 *
	 * @param string $rawBody The raw request body bytes (signature is computed over this).
	 * @param string $signature The `X-Signature` (or equivalent) header value (hex digest).
	 *
	 * @return array{result: string, invalidated: int}
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) BsnValidationService::mask() is a
	 *  pure static log-masking helper with no instance state.
	 */
	public function handle(string $rawBody, string $signature): array {
		$secret = $this->appConfig->getValueString(
			Application::APP_ID,
			'brp.webhook_secret',
			''
		);
		if ($secret === '') {
			$this->logger->warning('BRP webhook secret not configured; dropping delivery');
			return ['result' => self::RESULT_FORBIDDEN, 'invalidated' => 0];
		}

		// Constant-time signature comparison (ADR-005 — never use string equality on secrets).
		$expected = hash_hmac('sha256', $rawBody, $secret);
		if (hash_equals($expected, $signature) === false) {
			$this->logger->warning('BRP webhook signature mismatch');
			return ['result' => self::RESULT_FORBIDDEN, 'invalidated' => 0];
		}

		try {
			$payload = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
		} catch (Throwable $e) {
			$this->logger->info('BRP webhook payload not valid JSON');
			return ['result' => self::RESULT_BAD_REQUEST, 'invalidated' => 0];
		}

		if (is_array($payload) === false) {
			return ['result' => self::RESULT_BAD_REQUEST, 'invalidated' => 0];
		}

		$bsn = (string)($payload['burgerservicenummer'] ?? $payload['bsn'] ?? '');
		if ($bsn === '' || strlen($bsn) !== 9 || ctype_digit($bsn) === false) {
			return ['result' => self::RESULT_BAD_REQUEST, 'invalidated' => 0];
		}

		$count = $this->cacheService->invalidate($bsn);
		$this->auditService->recordLookup(
			actor: 'system:brp-webhook',
			rawBsn: $bsn,
			verzoekreden: 'BRP mutation webhook',
			purposeBinding: 'Cache-consistentie (BRP mutatie)',
			outcome: 'cache-invalidated',
			action: 'brp-cache-invalidated',
			responseCode: 200,
			actorRole: 'system',
		);

		$this->logger->info(
			'BRP webhook invalidated cache',
			['bsn' => BsnValidationService::mask($bsn), 'count' => $count]
		);

		return ['result' => self::RESULT_OK, 'invalidated' => $count];
	}//end handle()
}//end class
