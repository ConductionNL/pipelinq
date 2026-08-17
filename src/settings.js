import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { createApp } from 'vue'
import Settings from './views/settings/Settings.vue'
import pinia from './pinia.js'
import { initializeStores } from './store/store.js'

// Vue 3 has no global `Vue.mixin` / `Vue.use`; both are per-app-instance, and
// Pinia is a normal plugin now (`PiniaVuePlugin` was Vue-2 only).
const app = createApp(Settings)
app.mixin({ methods: { t, n } })
app.use(pinia)

// Register object types in the store before mounting, so the object-backed
// sections (pipelines, product categories, queues, …) can read/write objects
// in their own mounted() hooks. The settings page is its own webpack entry, so
// — unlike the main app entry (src/main.js), which relies on useListView's
// retry logic — it must register types itself and wait for that to finish.
// Mounting before registration completes races the child mounted() hooks and
// fails with "Object type X is not registered in the store".
//
// Under Vue 2 the store calls inside initializeStores() resolved because
// constructing the Vue instance ran Pinia's beforeCreate mixin, which called
// setActivePinia(). Vue 3's `app.use(pinia)` calls setActivePinia() at install
// time — i.e. on the line above — so the same guarantee holds before mount().
initializeStores().finally(() => {
	app.mount('#pipelinq-settings')
})
