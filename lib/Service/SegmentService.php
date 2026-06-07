<?php

/**
 * Pipelinq SegmentService.
 *
 * Rule-tree validator and evaluator that makes a Segment a live query, not
 * a frozen list. Validates and evaluates AND/OR rule trees against the
 * configured entity schema (contact or customer), estimates membership
 * size with TTL caching, and projects the per-recipient send list used by
 * the blast engine (member 04).
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
 * @spec openspec/changes/marketing-segmentation-and-blast-02-segment-service/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * SegmentService — validates and evaluates Segment rule trees.
 *
 * Skeleton landed in task 2.1; validateRules, evaluateRules,
 * estimateSize, and getMembersForBlast land in follow-up tasks.
 *
 * @spec openspec/changes/marketing-segmentation-and-blast-02-segment-service/tasks.md#task-2.1
 */
class SegmentService
{
    /**
     * Constructor.
     *
     * @param ContainerInterface $container DI container — used to lazily
     *                                      fetch the OpenRegister
     *                                      ObjectService + SchemaMapper.
     * @param LoggerInterface    $logger    Logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()
}//end class
