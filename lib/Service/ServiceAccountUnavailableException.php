<?php

/**
 * Pipelinq ServiceAccountUnavailableException.
 *
 * Thrown when a service account cannot be used, before anything runs. It
 * carries 503 so a provider retries the delivery once an admin picks one.
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
 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-005-consent-gating-and-recording
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\AppFramework\Http;
use RuntimeException;

/**
 * No usable service account: nothing was written.
 *
 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-005-consent-gating-and-recording
 */
class ServiceAccountUnavailableException extends RuntimeException {
	/**
	 * The HTTP status a public caller gets.
	 *
	 * @return int Always 503.
	 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-005-consent-gating-and-recording
	 */
	public function getStatus(): int {
		return Http::STATUS_SERVICE_UNAVAILABLE;
	}//end getStatus()
}//end class
