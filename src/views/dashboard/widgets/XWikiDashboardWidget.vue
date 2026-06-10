<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Dashboard wrapper around the reusable XWikiWidget. Pre-binds
  - admin-configured default space + showSearch=true and a 6-column slot
  - footprint (sized by the manifest layout entry).
  -->
<template>
	<XWikiWidget
		:title="t('pipelinq', 'Knowledge base')"
		:space="defaultSpace"
		:limit="5"
		:show-search="true"
		@select="openExternal" />
</template>

<script>
import XWikiWidget from '../../../components/xwiki/XWikiWidget.vue'
import { useSettingsStore } from '../../../store/modules/settings.js'

export default {
	name: 'XWikiDashboardWidget',
	components: { XWikiWidget },
	computed: {
		defaultSpace() {
			try {
				const store = useSettingsStore()
				return (store.config && store.config.xwiki_default_space) || ''
			} catch (e) {
				return ''
			}
		},
	},
	methods: {
		openExternal(article) {
			if (article && article.url) {
				window.open(article.url, '_blank', 'noopener,noreferrer')
			}
		},
	},
}
</script>
