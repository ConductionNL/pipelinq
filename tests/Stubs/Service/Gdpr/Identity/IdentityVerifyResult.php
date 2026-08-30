<?php

/**
 * Test stub for OCA\OpenRegister\Service\Gdpr\Identity\IdentityVerifyResult.
 *
 * Mirrors the value-object signature shipped by openregister
 * (dsar-integration-seams). Used only where the openregister runtime is absent
 * (bare CI containers). Loaded via Composer's autoload-dev PSR-4 mapping. NOT
 * scanned by PHPCS.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Stubs\Service\Gdpr\Identity
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr\Identity;

/**
 * Stub of OR's three-state identity-verify result.
 */
final class IdentityVerifyResult {
	public const STATUS_VERIFIED = 'verified';
	public const STATUS_FAILED = 'failed';
	public const STATUS_NEEDS_MORE = 'needs-more';

	/**
	 * @param string $status One of the three permitted statuses.
	 * @param string $providerId The producing provider id.
	 * @param string|null $message Optional detail.
	 */
	public function __construct(
		private readonly string $status,
		private readonly string $providerId,
		private readonly ?string $message = null,
	) {
	}

	/**
	 * @param string $providerId The verifying provider id.
	 * @param string|null $message Optional detail.
	 *
	 * @return self
	 */
	public static function verified(string $providerId, ?string $message = null): self {
		return new self(status: self::STATUS_VERIFIED, providerId: $providerId, message: $message);
	}

	/**
	 * @param string $providerId The producing provider id.
	 * @param string|null $message Optional detail.
	 *
	 * @return self
	 */
	public static function failed(string $providerId, ?string $message = null): self {
		return new self(status: self::STATUS_FAILED, providerId: $providerId, message: $message);
	}

	/**
	 * @param string $providerId The producing provider id.
	 * @param string|null $message Optional detail.
	 *
	 * @return self
	 */
	public static function needsMore(string $providerId, ?string $message = null): self {
		return new self(status: self::STATUS_NEEDS_MORE, providerId: $providerId, message: $message);
	}

	/**
	 * @return string
	 */
	public function getStatus(): string {
		return $this->status;
	}

	/**
	 * @return string
	 */
	public function getProviderId(): string {
		return $this->providerId;
	}

	/**
	 * @return string|null
	 */
	public function getMessage(): ?string {
		return $this->message;
	}

	/**
	 * @return bool
	 */
	public function isVerified(): bool {
		return $this->status === self::STATUS_VERIFIED;
	}
}
