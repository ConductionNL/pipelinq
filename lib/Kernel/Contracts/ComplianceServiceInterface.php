<?php

/**
 * Contract of ComplianceService
 *
 * The public surface of ComplianceService that other components call, so they depend
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
 * Contract of ComplianceService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ComplianceServiceInterface {

	/**
	 * A send that promotes something. Suppression applies.
	 *
	 * @var string
	 */
	public const INTENT_PROMOTIONAL = 'promotional';

	/**
	 * Check every member of a Segment for a usable ConsentRecord on the
	 * requested channel.
	 *
	 * Returns a triple:
	 *
	 * - `compliant` (bool) — true when no member is missing consent.
	 * - `missingConsent` (string[]) — contactIds without a usable record.
	 * - `missingCount` (int) — convenience count of the above.
	 *
	 * Members without a contactId are conservatively treated as
	 * missing-consent (they cannot be matched to a ConsentRecord row, so
	 * the fail-safe rule applies). When SegmentService returns an empty
	 * recipient set (segment not found, OR unreachable, etc.) the result
	 * is `compliant: true, missingCount: 0` — there's nothing to send.
	 *
	 * @param string $segmentId Segment UUID or slug.
	 * @param string $channel "email" or "sms".
	 * @param string $intent `promotional` or `service`; a service message is never suppressed.
	 *
	 * @return array{compliant: bool, missingConsent: array<int, string>, missingCount: int, suppressed: array<int, string>, suppressedCount: int}
	 *
	 * @spec openspec/specs/marketing-compliance/spec.md#requirement-blast-cannot-send-without-lawful-basis
	 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-a-promotional-send-skips-a-customer-in-dunning
	 */
	public function checkSegmentCompliance(string $segmentId, string $channel, string $intent = self::INTENT_PROMOTIONAL): array;

	/**
	 * Create a CampaignTemplate after compliance validation.
	 *
	 * Calls `validateTemplate()` for the requested channel before
	 * persisting. An invalid template is rejected with a human-readable
	 * error string; the row is never written. `createdBy` is stamped
	 * from the authenticated user id (ADR-005).
	 *
	 * @param array<string, mixed> $payload Inbound template payload.
	 * @param string $createdByUid Authenticated user id.
	 *
	 * @return array{template?: array<string, mixed>, error?: string}
	 *
	 * @spec openspec/changes/archive/2026-06-14-marketing-segmentation-and-blast-06-rest-controllers/tasks.md#templatecontroller-task-2.8-of-giant
	 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-html-templates-keep-working-req-mbe-004
	 */
	public function createTemplate(array $payload, string $createdByUid): array;

	/**
	 * Fetch one CampaignTemplate by id.
	 *
	 * @param string $templateId Template UUID or slug.
	 *
	 * @return array<string, mixed>|null Template payload or null.
	 *
	 * @spec openspec/changes/archive/2026-06-14-marketing-segmentation-and-blast-06-rest-controllers/tasks.md#templatecontroller-task-2.8-of-giant
	 */
	public function getTemplateById(string $templateId): ?array;

	/**
	 * List CampaignTemplates with pagination envelope.
	 *
	 * @param int $page 1-based page number (clamped to >= 1).
	 * @param int $limit Page size (clamped 1..100).
	 *
	 * @return array{data: array<int, array<string, mixed>>, pagination: array{page: int, limit: int, total: int, pages: int}}
	 *
	 * @spec openspec/changes/archive/2026-06-14-marketing-segmentation-and-blast-06-rest-controllers/tasks.md#templatecontroller-task-2.8-of-giant
	 */
	public function listTemplates(int $page, int $limit): array;

	/**
	 * Patch an existing CampaignTemplate with compliance re-validation.
	 *
	 * Any change to body / channel / footer triggers a fresh
	 * `validateTemplate()` call so an operator cannot edit a template
	 * into a non-compliant state. `createdBy` / `createdAt` are
	 * preserved from the existing row; `updatedAt` is bumped.
	 *
	 * @param string $templateId Template UUID or slug.
	 * @param array<string, mixed> $patch Patch payload.
	 *
	 * @return array{template?: array<string, mixed>, error?: string}
	 *
	 * @spec openspec/changes/archive/2026-06-14-marketing-segmentation-and-blast-06-rest-controllers/tasks.md#templatecontroller-task-2.8-of-giant
	 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-html-templates-keep-working-req-mbe-004
	 */
	public function patchTemplate(string $templateId, array $patch): array;

	/**
	 * Validate a CampaignTemplate payload against the channel's rules.
	 *
	 * For email templates the body MUST embed `{{unsubscribe_link}}`
	 * (token literally present in `bodyHtml` or `bodyText`) AND the
	 * template MUST carry the sender's physical address in a non-empty
	 * `footerOverride`. That address is what PhysicalAddressRenderer puts
	 * in the mail, at an address token (see `PhysicalAddressRenderer::TOKENS`)
	 * or at the end of the body, so a token on its own is not enough: it
	 * would render empty.
	 *
	 * Returns `null` on success or a human-readable error string on
	 * failure. Callers (controller / save path) surface the error as a
	 * field-level validation error and refuse to persist.
	 *
	 * SMS templates have no footer requirement (carriers strip
	 * footers; the CAN-SPAM rule is email-specific) — this method
	 * returns null for any non-email channel.
	 *
	 * @param array<string, mixed> $templateData CampaignTemplate payload.
	 * @param string $channel "email" or "sms".
	 *
	 * @return string|null Error message or null when valid.
	 *
	 * @spec openspec/specs/marketing-compliance/spec.md#requirement-unsubscribe-footer-enforced-on-email-templates
	 */
	public function validateTemplate(array $templateData, string $channel): ?string;
}//end interface
