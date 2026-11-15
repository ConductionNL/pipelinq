<?php

/**
 * Contract of FilinqLetterAdapter
 *
 * The public surface of FilinqLetterAdapter that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of FilinqLetterAdapter.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FilinqLetterAdapterInterface {

	/**
	 * One of pipelinq's templates by id, or null when filinq has none by that
	 * id in the pipelinq namespace.
	 *
	 * A template of another app is answered as absent, so this endpoint cannot
	 * be used to render another app's documents.
	 *
	 * @param string $templateId The filinq template id.
	 *
	 * @return array{id: string, name: string, description: string}|null The template.
	 *
	 * @throws RuntimeException When filinq is absent.
	 *
	 * @spec openspec/specs/client-letters/spec.md#requirement-a-user-makes-a-letter-for-a-client-from-a-filinq-template-req-wlt-001
	 */
	public function findTemplate(string $templateId): ?array;

	/**
	 * The letter templates that belong to pipelinq, as id, name and description.
	 *
	 * @return list<array{id: string, name: string, description: string}> The templates, by name.
	 *
	 * @throws RuntimeException When filinq is absent or refuses the listing.
	 *
	 * @spec openspec/specs/client-letters/spec.md#requirement-a-user-makes-a-letter-for-a-client-from-a-filinq-template-req-wlt-001
	 */
	public function listTemplates(): array;

	/**
	 * Have filinq preview a template for these records and name the fields it fills.
	 *
	 * @param string                                                  $templateId The filinq template id.
	 * @param list<array{register: string, schema: string, id: string}> $dataRefs   The records the letter reads.
	 *
	 * @return array{html: string, fields: list<array<string, string|bool>>, complete: bool, warnings: list<string>}
	 *
	 * @throws RuntimeException When filinq is absent, cannot preview, or the preview fails.
	 *
	 * @spec openspec/specs/client-letters/spec.md
	 */
	public function preview(string $templateId, array $dataRefs): array;

	/**
	 * Have filinq fill a template and return the PDF; outputMode 'both' keeps a copy in the user's Files.
	 *
	 * @param string                                                  $templateId The filinq template id.
	 * @param list<array{register: string, schema: string, id: string}> $dataRefs   The records the letter reads.
	 * @param string                                                  $filename   The file name, without extension.
	 * @param string                                                  $outputMode 'both' keeps a copy in the user's Files, 'return' only returns it.
	 * @param list<array<string, mixed>>                              $listRefs   Lists the template reads beside the records
	 *                                                                            (filinq listRefs: register, schema, filter, as, order).
	 *
	 * @return array{content: string, fileId: int|null, path: string|null, warnings: list<string>} The letter.
	 *
	 * @throws RuntimeException When filinq is absent, no user acts, or the render fails.
	 *
	 * @spec openspec/specs/client-letters/spec.md#requirement-a-user-makes-a-letter-for-a-client-from-a-filinq-template-req-wlt-001
	 */
	public function render(string $templateId, array $dataRefs, string $filename, string $outputMode='both', array $listRefs=[]): array;

	/**
	 * Why filinq cannot make a letter on this instance, or null when it can.
	 *
	 * @return string|null The reason, or null when both services resolve.
	 *
	 * @spec openspec/specs/client-letters/spec.md#requirement-without-filinq-no-letter-is-offered-or-faked-req-wlt-003
	 */
	public function unavailableReason(): ?string;
}//end interface
