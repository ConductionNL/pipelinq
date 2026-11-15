<?php

/**
 * Contract of RoadmapFeatureCatalog
 *
 * The public surface of RoadmapFeatureCatalog that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

/**
 * Contract of RoadmapFeatureCatalog.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RoadmapFeatureCatalogInterface {

	/**
	 * The curated feature list the page shows (board PqRoadmap).
	 *
	 * @var string
	 */
	public const CURATED_PATH = __DIR__ . '/../../Settings/roadmap.json';

	/**
	 * Build the Features & Roadmap list from openspec/specs at runtime so the
	 * surface stays current with the specs without depending on a committed
	 * docs/features.json (which can drift). Cached per app version and curated file — the specs
	 * only change when the app updates — with the committed docs/features.json
	 * as a fallback for deploys that ship without openspec/.
	 *
	 * @return array<int, array{slug:string, title:string, summary:string, docsUrl:string}>
	 *
	 * @spec openspec/specs/features-roadmap/spec.md
	 */
	public function load(): array;

	/**
	 * The curated list's groups, key to caption, in the order the page shows them.
	 *
	 * @param string $path The curated file.
	 *
	 * @return array<string, string> The captions; empty without a curated file.
	 *
	 * @spec openspec/specs/roadmap-curated-list/spec.md#requirement-the-page-reads-the-curated-feature-list
	 */
	public function categories(string $path=self::CURATED_PATH): array;
}//end interface
