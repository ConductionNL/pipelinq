<?php

/**
 * Pipelinq LeadSourceController.
 *
 * Controller for managing lead source tags via SystemTagService.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Controller for lead source management.
 *
 * @spec openspec/specs/lead-management/spec.md#requirement-lead-crud-mvp
 */
class LeadSourceController extends SystemTagController {
	private const OBJECT_TYPE = 'pipelinq_lead_source';

	/**
	 * List all lead source tags.
	 *
	 * @return JSONResponse The response containing tags.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		return $this->listTags();
	}//end index()

	/**
	 * Create a new lead source tag.
	 *
	 * @return JSONResponse The response containing the created tag.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function create(): JSONResponse {
		return $this->createTag();
	}//end create()

	/**
	 * Rename a lead source tag.
	 *
	 * @param string $id The tag ID.
	 *
	 * @return JSONResponse The response containing the renamed tag.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function update(string $id): JSONResponse {
		return $this->renameTagById(id: $id);
	}//end update()

	/**
	 * Delete a lead source tag.
	 *
	 * @param string $id The tag ID.
	 *
	 * @return JSONResponse The response.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function destroy(string $id): JSONResponse {
		return $this->deleteTag(id: $id);
	}//end destroy()

	/**
	 * The object type of the system tags this controller manages.
	 *
	 * @return string The object type.
	 */
	protected function objectType(): string {
		return self::OBJECT_TYPE;
	}//end objectType()

	/**
	 * The controller name its failures are logged under.
	 *
	 * @return string The log label.
	 */
	protected function logLabel(): string {
		return 'LeadSourceController';
	}//end logLabel()
}//end class
