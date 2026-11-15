import { registerIcons, registerTranslations } from '@conduction/nextcloud-vue'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { createApp } from 'vue'
import EmailFastOutline from 'vue-material-design-icons/EmailFastOutline.vue'
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue'
import Headset from 'vue-material-design-icons/Headset.vue'
import PowerPlugOutline from 'vue-material-design-icons/PowerPlugOutline.vue'
import ShareVariant from 'vue-material-design-icons/ShareVariant.vue'
import Settings from './views/settings/Settings.vue'
import pinia from './pinia.js'
import { initializeStores } from './store/store.js'

// Library CSS. The admin page is its own entry and gets none from main.js:
// without this import it had 59 library rules against 7,779 on the app pages,
// so the library's settings sections and cards drew unstyled.
import '@conduction/nextcloud-vue/css/index.css'

// The library's own catalogue (version card, shell chrome). main.js registers
// it for the app pages; this entry is a separate bundle.
registerTranslations()

// Vue 3 has no global `Vue.mixin` / `Vue.use`; both are per-app-instance, and
// Pinia is a normal plugin now (`PiniaVuePlugin` was Vue-2 only).
const app = createApp(Settings)
app.mixin({ methods: { t, n } })
// The library labels card fields and table columns from the schema's English
// property titles through `cnTranslate`. On the app pages CnAppRoot provides
// it; this entry has no CnAppRoot, so it provides the app's catalogue itself.
app.provide('cnTranslate', (text, vars) => t('pipelinq', text, vars))
// The icons the Integrations tab's connection cards name (lib/Settings/connections.json).
registerIcons({ EmailFastOutline, EmailOutline, Headset, PowerPlugOutline, ShareVariant })
app.use(pinia)

// Register object types in the store before mounting, so the object-backed
// sections (pipelines, product categories, skills, …) can read/write objects
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
