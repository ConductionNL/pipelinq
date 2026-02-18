import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import pinia from './pinia.js'
import Settings from './views/settings/Settings.vue'

Vue.mixin({ methods: { t, n } })
Vue.use(PiniaVuePlugin)

new Vue({
	pinia,
	render: h => h(Settings),
}).$mount('#pipelinq-settings')
