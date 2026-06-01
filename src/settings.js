import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import pinia from './pinia.js'
import Settings from './views/settings/Settings.vue'
import { initializeStores } from './store/store.js'

Vue.mixin({ methods: { t, n } })
Vue.use(PiniaVuePlugin)

new Vue({
	pinia,
	render: h => h(Settings),
}).$mount('#pipelinq-settings')

// Register object types in the store before the sections read/write objects.
// The settings page is its own webpack entry, so — unlike the main app entry
// (src/main.js) — it must call initializeStores() itself. Without it every
// object-backed section fails with "Object type X is not registered in the
// store". Mounting with `pinia` above activates it so the store calls resolve.
initializeStores()
