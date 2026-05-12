<template>
	<CnFeaturesAndRoadmapView
		:repo="repo"
		:features="features"
		:disabled="disabled" />
</template>

<script>
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Features & Roadmap page — thin wrapper around `CnFeaturesAndRoadmapView`
// from `@conduction/nextcloud-vue`. Mounted as the manifest `custom` page
// `FeaturesRoadmap` (route `/features-roadmap`, surfaced from the Settings
// section of the nav). `repo` / `features` / `disabled` come from
// server-provided initial state when available, with sensible fallbacks so
// the page is usable without backend wiring (the roadmap proxy and the
// `openregister::features_roadmap_enabled` flag live on OpenRegister).

import { CnFeaturesAndRoadmapView } from '@conduction/nextcloud-vue'
import { loadState } from '@nextcloud/initial-state'

export default {
	name: 'FeaturesRoadmap',

	components: {
		CnFeaturesAndRoadmapView,
	},

	data() {
		return {
			repo: loadState('pipelinq', 'features_roadmap_repo', 'ConductionNL/pipelinq'),
			features: loadState('pipelinq', 'features_roadmap_features', []),
			disabled: loadState('pipelinq', 'features_roadmap_disabled', false),
		}
	},
}
</script>
