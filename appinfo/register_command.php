<?php

/**
 * Pipelinq console command registration.
 *
 * Registers the app's occ console commands. Nextcloud loads this file when
 * building the console application.
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 */

declare(strict_types=1);

use OCA\Pipelinq\Command\CompetitorWatchRunCommand;
use OCA\Pipelinq\Command\ConnectionAuditCommand;
use OCA\Pipelinq\Command\PortalCleanupCommand;
use OCA\Pipelinq\Command\SearchConsoleImportCommand;
use OCA\Pipelinq\Command\SeedDemoDataCommand;
use OCP\Server;

/*
 * @var \Symfony\Component\Console\Application $application
 */
$application->add(Server::get(PortalCleanupCommand::class));
$application->add(Server::get(SeedDemoDataCommand::class));
$application->add(Server::get(SearchConsoleImportCommand::class));
$application->add(Server::get(CompetitorWatchRunCommand::class));
$application->add(Server::get(ConnectionAuditCommand::class));
