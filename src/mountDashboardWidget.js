/**
 * Shared Nextcloud-Dashboard widget bootstrap (Vue 3).
 *
 * Every `pipelinq-*Widget.js` entry point registers one widget with
 * `OCA.Dashboard.register()` and mounts a single SFC into the element the
 * dashboard hands the callback. The six entries were byte-identical apart from
 * the widget id and the component, so the shape lives here once.
 *
 * Vue 3 notes that this file encodes, all of which are silent if got wrong:
 *
 *  - `Vue.extend(Component)` + `new View({ propsData })` is Vue 2. In Vue 3 the
 *    equivalent is `createApp(Component, props)` — props are passed as the
 *    second argument, NOT as `propsData`, which Vue 3 ignores entirely (the
 *    widget would render with its prop defaults and no error).
 *  - `Vue.mixin` / `Vue.use` are per-app-instance now; a global call would
 *    silently apply to nothing.
 *  - `PiniaVuePlugin` was Vue-2 only; pinia is a normal plugin.
 *  - `$mount(el)` REPLACED `el`; `mount(el)` renders INSIDE it. That is the
 *    correct behaviour for a dashboard widget, whose `el` is a container the
 *    dashboard owns and re-uses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

import { createApp } from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import pinia from './pinia.js'

/**
 * Register one Nextcloud dashboard widget backed by a Vue 3 SFC.
 *
 * @param {string} widgetId The dashboard widget id registered in PHP.
 * @param {object} Component The single-file component to mount.
 */
export function registerDashboardWidget(widgetId, Component) {
	OCA.Dashboard.register(widgetId, (el, { widget }) => {
		const app = createApp(Component, { title: widget.title })
		app.mixin({ methods: { t, n } })
		app.use(pinia)
		app.mount(el)
	})
}
