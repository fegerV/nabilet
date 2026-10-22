<script setup lang="ts">
/**
 * Страница приглашения: `#/r/{code}`.
 *
 * ЗАЧЕМ ОТДЕЛЬНАЯ СТРАНИЦА, А НЕ ТИХИЙ РЕДИРЕКТ
 *   Друг открыл ссылку из мессенджера и ещё не понял, куда попал. Тихий
 *   редирект на афишу сохранил бы код, но смёл бы единственный шанс объяснить,
 *   зачем его пригласили и что он получит за первую покупку. Эта страница —
 *   обещание награды и вход на витрину, поэтому редиректа здесь нет: дальше
 *   человек идёт сам, по кнопке.
 *
 * КОД ЗАПОМИНАЕТСЯ РАНЬШЕ, ЧЕМ ПОКАЗЫВАЕТСЯ ХОТЬ ЧТО-ТО
 *   `rememberReferralCode()` вызывается в `onMounted`, а не по клику: если
 *   покупатель закроет вкладку на этом экране и вернётся вчерашней вкладкой,
 *   заказ всё равно уйдёт с приглашением — обещание не зависит от того, дошёл
 *   ли человек до кнопки.
 *
 * НЕИЗВЕСТНЫЙ ИЛИ БИТЫЙ КОД
 *   Сервер решает, существует ли код (`ReferralService::attachToOrder()`):
 *   витрина не ходит за этим на сервер, потому что приглашение без покупки не
 *   начисляет ничего. Похожесть формы проверяет `isWellFormedReferralCode()`,
 *   и если код не похож — показываем ту же страницу с условиями, но без
 *   обещания конкретной суммы: соврать в награде хуже, чем не пообещать.
 */
import { computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import NButton from '@/components/ui/NButton.vue'
import NBadge from '@/components/ui/NBadge.vue'
import { isCustomerSession } from '@/lib/customer'
import { isWellFormedReferralCode, rememberReferralCode } from '@/lib/referral'

const props = defineProps<{ code: string }>()
const router = useRouter()

/** Код принят, только если похож на настоящий: опечатку не запоминаем. */
const accepted = computed(() => isWellFormedReferralCode(props.code))

onMounted(() => {
  if (accepted.value) rememberReferralCode(props.code)
})

function toCatalog(): void {
  router.push('/')
}

function toAccount(): void {
  router.push(isCustomerSession() ? '/account' : '/login')
}
</script>

<template>
  <div class="mx-auto max-w-content px-4 py-6 sm:px-6">
    <div class="surface-card relative overflow-hidden p-6 sm:p-8">
      <!-- `bg-stage`, а не `bg-gradient-stage`: в конфиге ключ называется
           `stage`, и второй вариант не даёт класса — подсветки просто нет. -->
      <div class="absolute inset-0 bg-stage opacity-60" aria-hidden="true" />

      <div class="relative max-w-xl">
        <NBadge tone="brand" size="md">Приглашение</NBadge>
        <h1 class="mt-3 font-display text-2xl font-bold tracking-tight text-content sm:text-3xl">
          Вас пригласил друг
        </h1>
        <p class="mt-2 text-sm text-muted sm:text-base">
          Вы перешли по персональной ссылке «Клуба зрителей». Ваш первый оплаченный заказ — по этой
          ссылке — принесёт бонусы обеим сторонам: и вам, и тому, кто поделился ссылкой.
        </p>
        <p v-if="accepted" class="mt-2 text-xs text-subtle">
          Код приглашения сохранён в этом браузере и применится автоматически при оформлении заказа.
        </p>
        <p v-else class="mt-2 text-xs text-subtle">
          Ссылка выглядит неполной — попросите друга прислать её ещё раз. Оформление заказа работает
          и без приглашения.
        </p>

        <div class="mt-5 flex flex-wrap items-center gap-3">
          <NButton variant="primary" icon="→" @click="toCatalog">Смотреть афишу</NButton>
          <NButton variant="secondary" @click="toAccount">
            {{ isCustomerSession() ? 'Личный кабинет' : 'Войти или зарегистрироваться' }}
          </NButton>
        </div>
      </div>
    </div>
  </div>
</template>
