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
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\BrpCacheServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\BrpMutationWebhookListenerInterface;
use OCA\Pipelinq\Kernel\Contracts\BsnAuditServiceInterface;
use OCA\Pipelinq\Service\BsnAudit\LookupAudit;
use OCA\Pipelinq\Service\BsnAudit\LookupReason;
use OCA\Pipelinq\Service\BsnAudit\LookupResult;
use OCA\Pipelinq\Service\BsnAudit\LookupSubject;
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
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-004-03
 */
class BrpMutationWebhookListener implements BrpMutationWebhookListenerInterface {
	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App config (webhook secret).
	 * @param BrpCacheServiceInterface $cacheService Cache service.
	 * @param BsnAuditServiceInterface $auditService Audit service.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private BrpCacheServiceInterface $cacheService,
		private BsnAuditServiceInterface $auditService,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Result codes for handle().
	 */
	public const RESULT_OK = 'ok';

	/**
	 * Process a single webhook delivery.
	 *
	 * @param string $rawBody The raw request body bytes (signature is computed over this).
	 * @param string $signature The `X-Signature` (or equivalent) header value (hex digest).
	 *
	 * @return array{result: string, invalidated: int}
	 *
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md
	 */
	public function handle(string $rawBody, string $signature): array {
		if ($this->isSignatureAccepted(rawBody: $rawBody, signature: $signature) === false) {
			return $this->outcome(result: self::RESULT_FORBIDDEN, invalidated: 0);
		}

		$bsn = $this->payloadBsn(rawBody: $rawBody);
		if ($bsn === null) {
			return $this->outcome(result: self::RESULT_BAD_REQUEST, invalidated: 0);
		}

		return $this->invalidate(bsn: $bsn);
	}//end handle()

	/**
	 * Whether a secret is configured and the signature is its HMAC-SHA256 of the body.
	 *
	 * @param string $rawBody   The raw request body bytes.
	 * @param string $signature The delivered hex digest.
	 *
	 * @return bool
	 */
	private function isSignatureAccepted(string $rawBody, string $signature): bool {
		$secret = $this->appConfig->getValueString(AppIdentity::APP_ID, 'brp.webhook_secret', '');
		if ($secret === '') {
			$this->logger->warning('BRP webhook secret not configured; dropping delivery');
			return false;
		}

		// Constant-time signature comparison (ADR-005 — never use string equality on secrets).
		if (hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature) === false) {
			$this->logger->warning('BRP webhook signature mismatch');
			return false;
		}

		return true;
	}//end isSignatureAccepted()

	/**
	 * The well-formed BSN the payload names, or null.
	 *
	 * @param string $rawBody The raw request body bytes.
	 *
	 * @return string|null
	 */
	private function payloadBsn(string $rawBody): ?string {
		$payload = $this->decodedPayload(rawBody: $rawBody);
		if ($payload === null) {
			return null;
		}

		$bsn = (string)($payload['burgerservicenummer'] ?? $payload['bsn'] ?? '');
		if (strlen($bsn) !== 9 || ctype_digit($bsn) === false) {
			return null;
		}

		return $bsn;
	}//end payloadBsn()

	/**
	 * The body decoded as a JSON object or list, or null.
	 *
	 * @param string $rawBody The raw request body bytes.
	 *
	 * @return array<mixed>|null
	 */
	private function decodedPayload(string $rawBody): ?array {
		try {
			$payload = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
		} catch (Throwable $e) {
			$this->logger->info('BRP webhook payload not valid JSON');
			return null;
		}

		if (is_array($payload) === false) {
			return null;
		}

		return $payload;
	}//end decodedPayload()

	/**
	 * Invalidate the cache for the BSN, audit it and report the count.
	 *
	 * @param string $bsn The BSN.
	 *
	 * @return array{result: string, invalidated: int}
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) BsnValidationService::mask() is a
	 *  pure static log-masking helper with no instance state.
	 */
	private function invalidate(string $bsn): array {
		$count = $this->cacheService->invalidate($bsn);
		$this->recordInvalidation(bsn: $bsn);
		$this->logger->info(
			'BRP webhook invalidated cache',
			['bsn' => BsnValidationService::mask($bsn), 'count' => $count]
		);

		return $this->outcome(result: self::RESULT_OK, invalidated: $count);
	}//end invalidate()

	/**
	 * Write the audit record of a webhook-driven cache invalidation.
	 *
	 * @param string $bsn The BSN.
	 *
	 * @return void
	 */
	private function recordInvalidation(string $bsn): void {
		$this->auditService->recordLookup(
			actor: 'system:brp-webhook',
			lookup: new LookupAudit(
				subject: new LookupSubject(rawBsn: $bsn),
				reason: new LookupReason(
					verzoekreden: 'BRP mutation webhook',
					purposeBinding: 'Cache-consistentie (BRP mutatie)',
					linkedRequest: null, vogScreening: false,
				),
				result: new LookupResult(outcome: 'cache-invalidated', action: 'brp-cache-invalidated', responseCode: 200),
				actorRole: 'system',
			),
		);
	}//end recordInvalidation()

	/**
	 * The result shape handle() returns.
	 *
	 * @param string $result      Result code.
	 * @param int    $invalidated Invalidated entry count.
	 *
	 * @return array{result: string, invalidated: int}
	 */
	private function outcome(string $result, int $invalidated): array {
		return ['result' => $result, 'invalidated' => $invalidated];
	}//end outcome()
}//end class
