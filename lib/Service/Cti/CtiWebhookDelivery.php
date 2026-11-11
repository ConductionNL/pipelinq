<?php

/**
 * Pipelinq CtiWebhookDelivery.
 *
 * One delivery to the public CTI webhook.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Cti
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/cti-screenpop-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Cti;


/**
 * The platform a CTI webhook delivery is for and its decoded body.
 *
 * @spec openspec/specs/cti-screenpop-adapter/spec.md
 */
final class CtiWebhookDelivery {
	/**
	 * Constructor.
	 *
	 * @param string              $platform Platform identifier.
	 * @param array<string,mixed> $payload  Decoded webhook body.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $platform,
		public readonly array $payload,
	) {
	}//end __construct()
}//end class
