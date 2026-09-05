<?php

/**
 * Pipelinq PortalException.
 *
 * A portal-domain exception that carries an HTTP status, a stable machine
 * `errorCode`, and a safe, user-facing message. Controllers map it directly to
 * a JSONResponse, so no stack trace, internal path, or framework detail ever
 * reaches a portal client (ADR-005: no stack traces on the public surface).
 *
 * @category Exception
 * @package  OCA\Pipelinq\Service\Portal
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/customer-portal/specs.md#REQ-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use Exception;

/**
 * Safe, status-bearing portal error.
 *
 * @spec exclude exception type: carries a failure mode, not a behaviour a requirement
 *   can specify
 */
class PortalException extends Exception {
	/**
	 * Constructor.
	 *
	 * @param int $status The HTTP status code.
	 * @param string $errorCode The stable machine error code.
	 * @param string $message The user-facing safe message.
	 * @param array<string, mixed> $context Extra safe fields for the response body.
	 */
	public function __construct(
		private int $status,
		private string $errorCode,
		string $message,
		private array $context = [],
	) {
		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * The HTTP status code.
	 *
	 * @return int The status.
	 * @spec exclude exception type: carries a failure mode, not a behaviour a requirement
	 *   can specify
	 */
	public function getStatus(): int {
		return $this->status;
	}//end getStatus()

	/**
	 * The stable machine error code.
	 *
	 * @return string The error code.
	 * @spec exclude exception type: carries a failure mode, not a behaviour a requirement
	 *   can specify
	 */
	public function getErrorCode(): string {
		return $this->errorCode;
	}//end getErrorCode()

	/**
	 * Extra safe context fields for the response body.
	 *
	 * @return array<string, mixed> The context.
	 * @spec exclude exception type: carries a failure mode, not a behaviour a requirement
	 *   can specify
	 */
	public function getContext(): array {
		return $this->context;
	}//end getContext()

	/**
	 * The full safe response body for this error.
	 *
	 * @return array<string, mixed> The body.
	 * @spec exclude exception type: carries a failure mode, not a behaviour a requirement
	 *   can specify
	 */
	public function toBody(): array {
		return array_merge(['errorCode' => $this->errorCode, 'message' => $this->getMessage()], $this->context);
	}//end toBody()
}//end class
