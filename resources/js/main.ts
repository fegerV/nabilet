import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import { router } from './router'
import { initMetrika, trackPageView } from './lib/metrika'
import { useStorefrontStore } from './stores/storefront'
import '../css/app.css'

const pinia = createPinia()
const app = createApp(App).use(pinia).use(router)

/**
 * Конфиг витрины (тема, брендинг, секции) грузится ДО монтирования.
 *
 * Иначе первая отрисовка идёт в дефолтном фиолете и через полсекунды
 * перекрашивается в бренд площадки — пользователь видит мигание. Ждём
 * ответа, но не бесконечно: если API витрины недоступен, через 1,2 с
 * отдаём интерфейс на локальных дефолтах — афиша обязана открыться.
 */
const storefront = useStorefrontStore(pinia)
const timeout = new Promise<void>((resolve) => window.setTimeout(resolve, 1200))

void Promise.race([storefront.load(), timeout]).then(() => {
  app.mount('#app')
})

// Яндекс Метрика (цели для Директа): конфиг тянется с сервера, счётчик
// грузится асинхронно и не блокирует первый рендер витрины.
void initMetrika()
router.afterEach((to) => trackPageView(to.fullPath))
