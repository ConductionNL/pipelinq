<?php

/**
 * Pipelinq ExportSinkRegistry.
 *
 * Resolves a destination type slug to its concrete {@see ExportSinkInterface}
 * adapter. Injected with the full set of adapters so the upload service stays
 * decoupled from the concrete sink classes and so tests can register a mock
 * sink for a given type without touching the warehouse transports.
 *
 * @category Adapter
 * @package  OCA\Pipelinq\Adapter
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-008
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Adapter;

use InvalidArgumentException;

/**
 * Registry / factory for export sink adapters keyed by destination type.
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-008
 */
class ExportSinkRegistry
{

    /**
     * Map of destination type slug to its adapter.
     *
     * @var array<string, ExportSinkInterface>
     */
    private array $sinks = [];

    /**
     * Constructor.
     *
     * @param iterable<ExportSinkInterface> $sinks The available sink adapters.
     */
    public function __construct(iterable $sinks=[])
    {
        foreach ($sinks as $sink) {
            $this->register(sink: $sink);
        }
    }//end __construct()

    /**
     * Register (or replace) a sink adapter by its type.
     *
     * @param ExportSinkInterface $sink The adapter.
     *
     * @return void
     */
    public function register(ExportSinkInterface $sink): void
    {
        $this->sinks[$sink->getType()] = $sink;
    }//end register()

    /**
     * Whether an adapter is registered for the given type.
     *
     * @param string $type The destination type slug.
     *
     * @return bool True when supported.
     */
    public function supports(string $type): bool
    {
        return isset($this->sinks[$type]);
    }//end supports()

    /**
     * Resolve the adapter for a destination type.
     *
     * @param string $type The destination type slug.
     *
     * @return ExportSinkInterface The adapter.
     *
     * @throws \InvalidArgumentException When no adapter handles the type.
     */
    public function get(string $type): ExportSinkInterface
    {
        if (isset($this->sinks[$type]) === false) {
            throw new InvalidArgumentException("No export sink adapter registered for type '{$type}'.");
        }

        return $this->sinks[$type];
    }//end get()
}//end class
