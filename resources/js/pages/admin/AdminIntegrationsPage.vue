<script setup lang="ts">
/**
 * Интеграции.
 *
 * Раздел про внешний мир: счётчик аналитики, адреса вебхуков для провайдера
 * оплат и карта сайта. Здесь же объяснение, куда эти адреса вставлять —
 * администратор не должен искать это в документации, когда подключает кассу.
 *
 * Секреты (counter_auth) сюда не отдаются принципиально: они живут только
 * в .env, и админский UI их не показывает и не принимает.
 */
import { computed, onMounted, ref } from 'vue'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import NSelect from '@/components/ui/NSelect.vue'
import NCheckbox from '@/components/ui/NCheckbox.vue'
import NCard from '@/components/ui/NCard.vue'
import NPageHeader from '@/components/ui/NPageHeader.vue'
import { useUiStore } from '@/stores/ui'
import { get, send } from '@/lib/api'

interface MetrikaSettings {
  counter_id?: number | null
  counter_type?: string | null
  safe_stage?: boolean
  accurate_track?: boolean
  ecommerce?: boolean
  currency?: string | null
  goals?: Record<string, string> | null
}

const ui = useUiStore()

const loading = ref(true)
const saving = ref(false)
const error = ref<string | null>(null)
const savedSnapshot = ref('')

/* Форма держит конкретные значения, а не «может быть null»: поля обязаны
 * чем-то показать организатору, поэтому пустые места заполнены дефолтами. */
const form = ref({
  counter_id: 0,
  counter_type: 'web',
  safe_stage: false,
  accurate_track: false,
  ecommerce: true,
  currency: 'RUB',
  goals: {} as Record<string, string>,
})

const origin = computed(() => (typeof window !== 'undefined' ? window.location.origin : ''))

/** Адреса, которые организатор вставляет в личный кабинет провайдера. */
const ENDPOINTS = computed(() => [
  { label: 'Вебхук ЮKassa', url: `${origin.value}/api/v1/webhooks/payment/yookassa`, hint: 'В личном кабинете ЮKassa: «Уведомления» → этот URL' },
  { label: 'Вебхук провайдера (универсальный)', url: `${origin.value}/api/v1/webhooks/{type}`, hint: 'Для остальных провайдеров — вместо {type} подставить код' },
  { label: 'Карта сайта', url: `${origin.value}/api/v1/sitemap.xml`, hint: 'Отдаётся в Яндекс.Вебмастер и Google Search Console' },
])

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const res = await get<{ data: MetrikaSettings }>('/admin/analytics/metrika')
    const payload = res.data as unknown as { data?: MetrikaSettings } | MetrikaSettings
    const data = (payload as { data?: MetrikaSettings }).data ?? (payload as MetrikaSettings)
    // counter_auth намеренно не приходит с сервера — секрет остаётся в .env.
    form.value = {
      counter_id: data?.counter_id ?? 0,
      counter_type: data?.counter_type ?? 'web',
      safe_stage: Boolean(data?.safe_stage),
      accurate_track: Boolean(data?.accurate_track),
      ecommerce: Boolean(data?.ecommerce),
      currency: data?.currency ?? 'RUB',
      goals: data?.goals ?? {},
    }
    savedSnapshot.value = JSON.stringify(form.value)
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    loading.value = false
  }
}

onMounted(load)

// Пока организатор что-то меняет — кнопка сохранения доступна; после
// сохранения снова гаснет: непонятно «сохранилось или нет» хуже всего.
const isDirty = computed(() => JSON.stringify(form.value) !== savedSnapshot.value)

async function save(): Promise<void> {
  saving.value = true
  try {
    const res = await send<{ data: MetrikaSettings }>('/admin/analytics/metrika', 'PUT', {
      counter_id: Number(form.value.counter_id ?? 0),
      counter_type: form.value.counter_type,
      safe_stage: form.value.safe_stage,
      accurate_track: form.value.accurate_track,
      ecommerce: form.value.ecommerce,
      currency: form.value.currency,
    })
    const payload = res.data as unknown as { data?: MetrikaSettings } | MetrikaSettings
    const data = (payload as { data?: MetrikaSettings }).data ?? (payload as MetrikaSettings)
    // Сливаем осознанно: сервер может прислать null (настройка не задана),
    // а форма обязана остаться заполненной.
    form.value = {
      counter_id: Number(data?.counter_id ?? form.value.counter_id),
      counter_type: data?.counter_type ?? form.value.counter_type,
      safe_stage: Boolean(data?.safe_stage ?? form.value.safe_stage),
      accurate_track: Boolean(data?.accurate_track ?? form.value.accurate_track),
      ecommerce: Boolean(data?.ecommerce ?? form.value.ecommerce),
      currency: data?.currency ?? form.value.currency,
      goals: data?.goals ?? form.value.goals,
    }
    savedSnapshot.value = JSON.stringify(form.value)
    ui.notify('mint', 'Настройки сохранены', 'Счётчик подхватится при следующей загрузке витрины.')
  } catch (e) {
    ui.notify('rose', 'Не удалось сохранить', e instanceof Error ? e.message : String(e))
  } finally {
    saving.value = false
  }
}

async function copy(url: string): Promise<void> {
  try {
    await navigator.clipboard.writeText(url)
    ui.notify('brand', 'Скопировано', url)
  } catch {
    ui.notify('sun', 'Скопируйте адрес вручную', url)
  }
}
</script>

<template>
  <div class="mx-auto max-w-[1100px]">
    <NPageHeader title="Интеграции" subtitle="Счётчик аналитики, вебхуки оплаты и карта сайта." />

    <div v-if="error" class="surface-card mt-5 border-rose-500/30 px-4 py-3 text-sm text-rose-400">
      Не удалось загрузить настройки: {{ error }}
    </div>

    <!-- Метрика -->
    <NCard
      class="mt-5"
      title="Яндекс Метрика"
      description="ID счётчика и цели для Директа. Токен доступа задаётся только в .env — в интерфейсе он не показывается."
    >
      <template #actions>
        <NButton variant="primary" size="sm" :loading="saving" :disabled="!isDirty" @click="save">Сохранить</NButton>
      </template>

      <div class="grid gap-4 sm:grid-cols-2">
        <NInput
          v-model="form.counter_id"
          label="ID счётчика"
          type="number"
          min="0"
          hint="0 — интеграция выключена"
        />
        <NSelect
          v-model="form.counter_type"
          label="Тип счётчика"
          :options="[
            { value: 'web', label: 'Веб-счётчик' },
            { value: 'hit', label: 'Hit-счётчик' },
          ]"
        />
      </div>

      <div class="mt-4 grid gap-3 sm:grid-cols-2">
        <NCheckbox v-model="form.ecommerce" label="Электронная коммерция" description="Передавать покупки в Метрику" />
        <NCheckbox v-model="form.safe_stage" label="Безопасный режим" description="Не отправлять данные на stage" />
        <NCheckbox v-model="form.accurate_track" label="Точный трекинг" description="Точнее, но тяжелее для страницы" />
      </div>
    </NCard>

    <!-- Адреса -->
    <NCard class="mt-5" title="Адреса для внешних сервисов">
      <div class="space-y-3">
        <div v-for="endpoint in ENDPOINTS" :key="endpoint.label" class="rounded-lg border border-line bg-surface-2 p-3">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm text-content">{{ endpoint.label }}</p>
            <NButton variant="ghost" size="sm" @click="copy(endpoint.url)">Копировать</NButton>
          </div>
          <p class="mt-1 break-all font-mono text-xs text-brand-400">{{ endpoint.url }}</p>
          <p class="mt-1 text-xs text-subtle">{{ endpoint.hint }}</p>
        </div>
      </div>
    </NCard>

    <!-- Цели -->
    <NCard v-if="form.goals && Object.keys(form.goals).length" class="mt-5" title="Цели, которые отправляет витрина">
      <div class="flex flex-wrap gap-2">
        <span
          v-for="(goal, key) in form.goals"
          :key="key"
          class="rounded-full border border-line bg-surface-2 px-3 py-1 text-xs text-muted"
        >
          {{ key }} → {{ goal }}
        </span>
      </div>
    </NCard>
  </div>
</template>
