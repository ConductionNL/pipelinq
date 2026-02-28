import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import pinia from './pinia.js'
import router from './router/index.js'
import App from './App.vue'

// Library CSS — must be explicit import (webpack tree-shakes side-effect imports from aliased packages)
import '@conduction/nextcloud-vue/css/index.css'

Vue.mixin({ methods: { t, n } })
Vue.use(PiniaVuePlugin)

new Vue({
	pinia,
	router,
	render: h => h(App),
}).$mount('#content')
