<?php

/**
 * Pipelinq McpAnswer.
 *
 * The shape every MCP tool in this app answers in, written once.
 *
 * LeadService and TicketService each carried their own copy of these three
 * methods, byte for byte, and each copy was private, so nothing could tell
 * whether the two surfaces still answered alike. They did, which is luck
 * rather than design: an error code corrected in one copy would have left the
 * other one answering the old word, and a language model reading the answer
 * would have had no way to notice.
 *
 * THIS SHAPES ANSWERS, IT DOES NOT DECIDE THEM. Whether a call failed, and
 * what it was trying to do, stays with the service that made the call. What
 * belongs here is only how that comes out as JSON a model can read.
 *
 * @category Mcp
 * @package  OCA\Pipelinq\Mcp
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Mcp;

use Exception;
use JsonSerializable;
use Psr\Log\LoggerInterface;

/**
 * Build the answers pipelinq's MCP tools return.
 */
class McpAnswer {
	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger PSR logger, for the operator's copy of a failure.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * A structured MCP error envelope.
	 *
	 * @param string $code Machine-readable error code.
	 * @param string $message Human-readable message for the model.
	 *
	 * @return array<string, mixed> The envelope.
	 */
	public function error(string $code, string $message): array {
		return [
			'error' => [
				'code' => $code,
				'message' => $message,
			],
		];
	}//end error()

	/**
	 * The envelope an exception raised by OpenRegister becomes.
	 *
	 * OpenRegister's PermissionHandler raises a plain exception whose message
	 * mentions "permission" when the caller is not authorised, so that is
	 * surfaced as `forbidden` and says nothing further. Everything else is an
	 * `internal_error`, with the detail going to the log rather than to the
	 * model.
	 *
	 * @param string $operation Short label of the failed operation, for the log.
	 * @param Exception $exception The caught exception.
	 *
	 * @return array<string, mixed> The envelope.
	 */
	public function fromException(string $operation, Exception $exception): array {
		$message = $exception->getMessage();

		if (stripos($message, 'permission') !== false || stripos($message, 'not authoriz') !== false) {
			return $this->error(code: 'forbidden', message: 'You are not allowed to access this resource.');
		}

		$this->logger->error(
			"Pipelinq MCP: failed to {$operation}",
			['exception' => $message]
		);

		return $this->error(
			code: 'internal_error',
			message: "Failed to {$operation}. See server log for details."
		);
	}//end fromException()

	/**
	 * An OpenRegister object as a plain array.
	 *
	 * @param mixed $item Raw item from ObjectService.
	 *
	 * @return array<string, mixed> The object's properties.
	 */
	public function toArray(mixed $item): array {
		if (is_array($item) === true) {
			return $item;
		}

		if (is_object($item) === true && method_exists($item, 'getObject') === true) {
			return $item->getObject();
		}

		if ($item instanceof JsonSerializable) {
			return (array)$item->jsonSerialize();
		}

		return (array)$item;
	}//end toArray()
}//end class
