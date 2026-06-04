import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import pinia from './pinia.js'
import Settings from './views/settings/Settings.vue'
import { initializeStores } from './store/store.js'

Vue.mixin({ methods: { t, n } })
Vue.use(PiniaVuePlugin)

// Constructing the Vue instance runs Pinia's beforeCreate mixin, which calls
// setActivePinia() — so the store calls inside initializeStores() resolve even
// before $mount.
const app = new Vue({
	pinia,
	render: h => h(Settings),
})

// Register object types in the store before mounting, so the object-backed
// sections (pipelines, product categories, queues, …) can read/write objects
// in their own mounted() hooks. The settings page is its own webpack entry, so
// — unlike the main app entry (src/main.js), which relies on useListView's
// retry logic — it must register types itself and wait for that to finish.
// Mounting before registration completes races the child mounted() hooks and
// fails with "Object type X is not registered in the store".
initializeStores().finally(() => {
	app.$mount('#pipelinq-settings')
})
