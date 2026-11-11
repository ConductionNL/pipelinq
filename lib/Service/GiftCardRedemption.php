<?php

/**
 * Pipelinq GiftCardRedemption.
 *
 * One gift card redemption: the card, the PIN offered, the amount and the
 * POS transaction it belongs to.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-007
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * What a till asks when it redeems a gift card.
 *
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-007-01
 */
final class GiftCardRedemption {
	/**
	 * Constructor.
	 *
	 * @param string      $giftCardId       The card UUID.
	 * @param string      $pin              The plaintext PIN; never logged.
	 * @param float       $amount           The amount to redeem.
	 * @param string|null $posTransactionId The POS transaction id, when supplied.
	 */
	public function __construct(
		public readonly string $giftCardId,
		#[\SensitiveParameter]
		public readonly string $pin,
		public readonly float $amount,
		public readonly ?string $posTransactionId = null,
	) {
	}//end __construct()
}//end class
