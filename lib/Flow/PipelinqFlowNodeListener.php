<?php

/**
 * Pipelinq Flow Node Listener.
 *
 * Presents Pipelinq's own flow steps to OpenRegister's flow engine. ADR-065:
 * OpenRegister owns the flow engine and no leaf app grows a second one, so
 * Pipelinq does not keep one. It CONTRIBUTES what it can do, which is what
 * `FlowNodeRegistry` is built for and what dossiq, hermiq and humaniq already
 * do.
 *
 * Nodes are resolved from a class-string list rather than injected one per
 * constructor parameter, so adding a node stays one line. A node that cannot
 * be constructed is logged and SKIPPED rather than aborting the loop: one
 * unresolvable dependency must not cost the other nodes their place in the
 * catalogue, and a missing node is visible (the editor simply does not offer
 * it) where a failed registration is not.
 *
 * @category Flow
 * @package  OCA\Pipelinq\Flow
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-watch-runs-on-an-openregister-flow-schedule-and-never-on-a-job-of-ours
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Flow;

use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Registers Pipelinq's nodes on OpenRegister's flow catalogue.
 *
 * @template-implements IEventListener<Event>
 *
 * @psalm-suppress MissingDependency Every class in NODES implements
 *     OpenRegister's IFlowNode, which is suppressed as an undefined class in
 *     psalm.xml (cross-app, runtime-loaded), so psalm cannot resolve the
 *     dependency chain.
 * @psalm-suppress InvalidConstantAssignmentValue Every class listed in NODES
 *     is a real class-string, but with IFlowNode unresolvable psalm reads the
 *     ::class literals as mixed and rejects the narrowing.
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-watch-runs-on-an-openregister-flow-schedule-and-never-on-a-job-of-ours
 */
class PipelinqFlowNodeListener implements IEventListener {

	/**
	 * The nodes Pipelinq contributes.
	 *
	 * @var array<int, class-string>
	 *
	 * @psalm-suppress MissingDependency Every class here implements
	 *     OpenRegister's IFlowNode, suppressed as undefined in psalm.xml.
	 * @psalm-suppress InvalidConstantAssignmentValue With IFlowNode
	 *     unresolvable psalm reads the ::class literals as mixed.
	 */
	private const NODES = [
		CompetitorWatchRunNode::class,
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves each node.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-watch-runs-on-an-openregister-flow-schedule-and-never-on-a-job-of-ours
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Register the Pipelinq nodes on the catalogue.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-watch-runs-on-an-openregister-flow-schedule-and-never-on-a-job-of-ours
	 */
	public function handle(Event $event): void {
		if ($event instanceof RegisterFlowNodesEvent === false) {
			return;
		}

		foreach (self::NODES as $nodeClass) {
			try {
				$node = $this->container->get($nodeClass);
			} catch (Throwable $e) {
				$this->logger->warning(
					'pipelinq: flow node ' . $nodeClass . ' could not be registered: ' . $e->getMessage(),
					['app' => 'pipelinq']
				);
				continue;
			}

			$event->registerNode($node);
		}
	}//end handle()

	/**
	 * The node classes this listener registers, so a declaration test can
	 * check the shipped flow and the palette against one list.
	 *
	 * @return array<int, class-string>
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-watch-runs-on-an-openregister-flow-schedule-and-never-on-a-job-of-ours
	 */
	public static function nodeClasses(): array {
		return self::NODES;
	}//end nodeClasses()
}//end class
