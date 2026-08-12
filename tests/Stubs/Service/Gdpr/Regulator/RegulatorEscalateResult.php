<?php

/**
 * Test stub for OCA\OpenRegister\Service\Gdpr\Regulator\RegulatorEscalateResult.
 *
 * Mirrors the value-object signature shipped by openregister
 * (dsar-integration-seams). Used only where the openregister runtime is absent
 * (bare CI containers). Loaded via Composer's autoload-dev PSR-4 mapping. NOT
 * scanned by PHPCS.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Stubs\Service\Gdpr\Regulator
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr\Regulator;

/**
 * Stub of OR's regulator-escalate result.
 */
final class RegulatorEscalateResult {
	public const STATUS_ESCALATED = 'escalated';
	public const STATUS_REFUSED = 'refused';

	/**
	 * @param string $status One of the two permitted statuses.
	 * @param string $providerId The producing provider id.
	 * @param string $reference The regulator reference (empty when refused).
	 * @param string|null $message Optional detail.
	 */
	public function __construct(
		private readonly string $status,
		private readonly string $providerId,
		private readonly string $reference = '',
		private readonly ?string $message = null,
	) {
	}

	/**
	 * @param string $providerId The escalating provider id.
	 * @param string $reference The regulator reference returned.
	 * @param string|null $message Optional detail.
	 *
	 * @return self
	 */
	public static function escalated(string $providerId, string $reference, ?string $message = null): self {
		return new self(status: self::STATUS_ESCALATED, providerId: $providerId, reference: $reference, message: $message);
	}

	/**
	 * @param string $providerId The refusing provider id.
	 * @param string|null $message Optional detail.
	 *
	 * @return self
	 */
	public static function refused(string $providerId, ?string $message = null): self {
		return new self(status: self::STATUS_REFUSED, providerId: $providerId, reference: '', message: $message);
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
	 * @return string
	 */
	public function getReference(): string {
		return $this->reference;
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
	public function isEscalated(): bool {
		return $this->status === self::STATUS_ESCALATED;
	}
}
