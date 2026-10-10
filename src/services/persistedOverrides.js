// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { mergeManifestDelta, useAppStatus } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Load the persisted buildiq app-override delta and merge it over the
 * build-time manifest (ADR-041 round-trip: App.vue's persistManifestDelta PUTs
 * edits to this store; this loader brings them back at boot). The GET returns
 * the LAYERED delta (shared admin delta ⊕ the calling user's own delta), or
 * `{}` when no override exists. Fail-soft: any error, buildiq not
 * installed, endpoint unreachable, malformed delta — falls back to the
 * build-time manifest, so an override can never prevent the app from booting.
 *
 * Without buildiq (or its old id openbuild) enabled for the user there is no
 * store to ask, so the loader returns the manifest without a request. Before,
 * every page load sent the GET and got a 404 back. The check reads the same
 * keys as the library's useBuildiqEditAvailability.
 *
 * @param {object} manifest The build-time merged manifest.
 * @return {Promise<object>} The manifest with persisted overrides applied.
 *
 * @spec openspec/changes/round6-nextcloud-vue-2-78/specs/declarative-view-system/spec.md
 */
export async function loadPersistedOverrides(manifest) {
	try {
		if (!isBuildiqAvailable()) {
			return manifest
		}
		const { data } = await axios.get(
			generateUrl('/apps/buildiq/api/app-overrides/pipelinq'),
			{ timeout: 8000 },
		)
		if (
			data !== null
			&& typeof data === 'object'
			&& !Array.isArray(data)
			&& Object.keys(data).length > 0
		) {
			const { manifest: merged, orphanedDeltaPaths } = mergeManifestDelta(
				manifest,
				data,
			)
			if (orphanedDeltaPaths.length > 0) {
				console.warn(
					'[pipelinq] Manifest override has orphaned delta paths (base changed since the edit):',
					orphanedDeltaPaths,
				)
			}
			return merged
		}
	} catch (error) {
		// A 404 is the ordinary "buildiq is not installed" answer, not a fault —
		// warning on it puts an AxiosError in every console on every boot.
		if (error?.response?.status !== 404) {
			console.warn(
				'[pipelinq] Could not load persisted manifest overrides — using the bundled manifest.',
				error,
			)
		}
	}
	return manifest
}

/**
 * Whether buildiq, the store behind persisted overrides, is enabled for the
 * current user. openbuild is buildiq's old app id.
 *
 * @return {boolean}
 *
 * @spec openspec/changes/round6-nextcloud-vue-2-78/specs/declarative-view-system/spec.md
 */
function isBuildiqAvailable() {
	return (
		useAppStatus('buildiq').enabled.value
		|| useAppStatus('openbuild').enabled.value
	)
}
