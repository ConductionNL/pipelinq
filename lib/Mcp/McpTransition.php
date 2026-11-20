<?php

/**
 * Pipelinq McpTransition.
 *
 * Runs one declared lifecycle transition for an MCP tool and answers in the
 * MCP envelope.
 *
 * A lifecycle state on the MCP surface moves only through the transition the
 * register declares in `x-openregister-lifecycle`, applied by OpenRegister's
 * TransitionEngine: the engine owns the `from`-state check, the per-object
 * `update` RBAC, the transition's `authorization` groups and its `requires`
 * guard. This class adds nothing to that decision. It only turns the engine's
 * answer into what a language model can read: the moved object, or a
 * `forbidden`, `not_found` or `conflict` envelope, never a partial success.
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
 *
 * @spec openspec/changes/mcp-full-action-coverage/specs/crm-mcp-tool-surface/spec.md#requirement-lifecycle-transitions-are-curated-tools-one-per-action
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Mcp;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Throwable;

/**
 * One lifecycle transition, answered as an MCP envelope.
 *
 * @spec openspec/changes/mcp-full-action-coverage/specs/crm-mcp-tool-surface/spec.md#requirement-lifecycle-transitions-are-curated-tools-one-per-action
 */
class McpTransition {

	/**
	 * OpenRegister's TransitionEngine, resolved lazily so pipelinq loads without it.
	 *
	 * @var string
	 */
	private const TRANSITION_ENGINE = 'OCA\\OpenRegister\\Service\\Lifecycle\\TransitionEngine';

	/**
	 * Exception short names that mean the caller may not make the move.
	 *
	 * `NotAuthorizedException` is the engine's own RBAC and `authorization`
	 * refusal; `HookStoppedException` carries a `requires` guard's deny.
	 *
	 * @var list<string>
	 */
	private const REFUSED = ['NotAuthorizedException', 'HookStoppedException'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container (for the TransitionEngine).
	 * @param McpAnswer          $mcp       Shapes the answer.
	 * @param LoggerInterface    $logger    For the operator's copy of an unexpected failure.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly McpAnswer $mcp,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Apply the declared transition `$action` to the object `$objectId`.
	 *
	 * @param string $objectId The object's uuid.
	 * @param string $action   The transition name declared on its schema.
	 * @param string $subject  The key the moved object is answered under, e.g. `lead`.
	 *
	 * @return array<string, mixed> `[$subject => object]`, or an MCP error envelope.
	 *
	 * @spec openspec/changes/mcp-full-action-coverage/specs/crm-mcp-tool-surface/spec.md#requirement-lifecycle-transitions-are-curated-tools-one-per-action
	 */
	public function run(string $objectId, string $action, string $subject): array {
		$objectId = trim($objectId);
		if ($objectId === '') {
			return $this->mcp->error(code: 'invalid_arguments', message: 'Required argument id is missing.');
		}

		try {
			$engine = $this->container->get(self::TRANSITION_ENGINE);
		} catch (Throwable $e) {
			return $this->mcp->error(
				code: 'not_configured',
				message: 'OpenRegister lifecycle transitions are not available on this instance.'
			);
		}

		try {
			$moved = $engine->transition(objectId: $objectId, action: $action);
		} catch (Throwable $e) {
			return $this->refusal(error: $e, action: $action, subject: $subject);
		}

		return [$subject => $this->mcp->toArray(item: $moved)];
	}//end run()

	/**
	 * The envelope a failed transition becomes.
	 *
	 * @param Throwable $error   What the engine threw.
	 * @param string    $action  The transition attempted.
	 * @param string    $subject The kind of object, for the message.
	 *
	 * @return array<string, mixed> A forbidden, not_found, conflict or internal_error envelope.
	 *
	 * @spec openspec/changes/mcp-full-action-coverage/specs/crm-mcp-tool-surface/spec.md#requirement-lifecycle-transitions-are-curated-tools-one-per-action
	 */
	private function refusal(Throwable $error, string $action, string $subject): array {
		$short = (new ReflectionClass($error))->getShortName();
		$message = $error->getMessage();

		if (in_array($short, self::REFUSED, true) === true || stripos($message, 'permission') !== false) {
			return $this->mcp->error(code: 'forbidden', message: "You are not allowed to {$action} this {$subject}.");
		}

		if ($short === 'LifecycleSubjectNotFoundException' || stripos($message, 'not found') !== false) {
			return $this->mcp->error(code: 'not_found', message: "No {$subject} with that id.");
		}

		if (stripos($message, 'not allowed from') !== false) {
			return $this->mcp->error(code: 'conflict', message: $message);
		}

		$this->logger->error(
			"Pipelinq MCP: failed to {$action} {$subject}",
			['exception' => $message]
		);

		return $this->mcp->error(
			code: 'internal_error',
			message: "Failed to {$action} {$subject}. See server log for details."
		);
	}//end refusal()
}//end class
