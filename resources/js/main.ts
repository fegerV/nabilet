import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import { router } from './router'
import { initMetrika, trackPageView } from './lib/metrika'
import '../css/app.css'

const app = createApp(App).use(createPinia()).use(router)

// Яндекс Метрика (цели для Директа): конфиг тянется с сервера, счётчик
// грузится асинхронно и не блокирует первый рендер витрины.
void initMetrika()
router.afterEach((to) => trackPageView(to.fullPath))

app.mount('#app')
