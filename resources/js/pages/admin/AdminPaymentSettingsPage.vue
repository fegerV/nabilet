<script setup lang="ts">
/**
 * Приём платежей — настройка шлюза ЮKassa (Яндекс Касса).
 *
 * Смысл экрана: включить приём оплаты без правки .env и без доступа к серверу.
 * До этого ключи жили только в `.env`, а на шаред-хостинге владелец магазина
 * туда не заглядывает — экран закрывает ровно эту дыру.
 *
 * Секреты (secret_key, webhook_secret) с сервера НЕ приходят: показывается
 * только признак «задан». Пустое поле = «не менять», стереть — отдельная кнопка,
 * чтобы обычное сохранение формы не рвало работающий шлюз.
 *
 * Demo-режим — локальный симулятор оплаты: ключи не нужны, деньги не двигаются.
 * Он же позволяет открыть кассу до получения реальных ключей ЮKassa.
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

interface PaymentSettingsData {
  shop_id: string
  shop_id_from_env: boolean
  secret_key_set: boolean
  webhook_secret_set: boolean
  demo_mode: boolean
  demo_mode_from_env: boolean
  default_provider: string
  default_provider_from_env: boolean
  available_providers: string[]
}

const ui = useUiStore()

const loading = ref(true)
const saving = ref(false)
const error = ref<string | null>(null)
const savedSnapshot = ref('')

const form = ref({
  shop_id: '',
  secret_key: '',
  webhook_secret: '',
  demo_mode: false,
  default_provider: 'yookassa',
})

const fromEnv = ref({
  shop_id: false,
  demo_mode: false,
  default_provider: false,
})
const secretKeySet = ref(false)
const webhookSecretSet = ref(false)
const providers = ref<string[]>(['yookassa'])

const providerOptions = computed(() =>
  providers.value.map((p) => ({ value: p, label: p === 'yookassa' ? 'ЮKassa (Яндекс Касса)' : p })),
)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const res = await get<{ data: PaymentSettingsData }>('/admin/payments/settings')
    const payload = res.data as unknown as { data?: PaymentSettingsData }
    const data = (payload.data ?? (res.data as unknown as PaymentSettingsData))
    form.value = {
      shop_id: data?.shop_id ?? '',
      // Секреты с сервера не приходят — поля всегда пустые.
      secret_key: '',
      webhook_secret: '',
      demo_mode: Boolean(data?.demo_mode),
      default_provider: data?.default_provider || 'yookassa',
    }
    fromEnv.value = {
      shop_id: Boolean(data?.shop_id_from_env),
      demo_mode: Boolean(data?.demo_mode_from_env),
      default_provider: Boolean(data?.default_provider_from_env),
    }
    secretKeySet.value = Boolean(data?.secret_key_set)
    webhookSecretSet.value = Boolean(data?.webhook_secret_set)
    providers.value = data?.available_providers?.length ? data.available_providers : ['yookassa']
    savedSnapshot.value = JSON.stringify(form.value)
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    loading.value = false
  }
}

onMounted(load)

const isDirty = computed(() => JSON.stringify(form.value) !== savedSnapshot.value)

async function save(): Promise<void> {
  saving.value = true
  try {
    const body: Record<string, unknown> = {
      shop_id: form.value.shop_id.trim(),
      demo_mode: form.value.demo_mode,
      default_provider: form.value.default_provider,
    }
    // Пустая строка = «не менять», поэтому ключ вообще не отправляем.
    if (form.value.secret_key !== '') body.secret_key = form.value.secret_key
    if (form.value.webhook_secret !== '') body.webhook_secret = form.value.webhook_secret

    const res = await send<{ data: PaymentSettingsData }>('/admin/payments/settings', 'PUT', body)
    const payload = res.data as unknown as { data?: PaymentSettingsData }
    const data = payload.data ?? (res.data as unknown as PaymentSettingsData)

    form.value.secret_key = ''
    form.value.webhook_secret = ''
    secretKeySet.value = Boolean(data?.secret_key_set)
    webhookSecretSet.value = Boolean(data?.webhook_secret_set)
    savedSnapshot.value = JSON.stringify(form.value)
    ui.notify('mint', 'Настройки сохранены', 'Платежи уйдут с новыми параметрами сразу.')
  } catch (e) {
    ui.notify('rose', 'Не удалось сохранить', e instanceof Error ? e.message : String(e))
  } finally {
    saving.value = false
  }
}

async function clearSecret(which: 'secret_key' | 'webhook_secret'): Promise<void> {
  saving.value = true
  try {
    const body = which === 'secret_key' ? { clear_secret_key: true } : { clear_webhook_secret: true }
    await send('/admin/payments/settings', 'PUT', body)
    if (which === 'secret_key') secretKeySet.value = false
    else webhookSecretSet.value = false
    form.value[which] = ''
    ui.notify('sun', which === 'secret_key' ? 'Секретный ключ удалён' : 'Секрет вебхука удалён', 'Шлюз будет работать без него.')
  } catch (e) {
    ui.notify('rose', 'Не удалось удалить', e instanceof Error ? e.message : String(e))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-[900px]">
    <NPageHeader
      title="Приём платежей"
      subtitle="Ключи платёжного шлюза. Настраиваются здесь, без правки файлов на сервере: значения из этой формы перекрывают .env."
    />

    <div v-if="error" class="surface-card mt-5 border-rose-500/30 px-4 py-3 text-sm text-rose-400">
      Не удалось загрузить настройки: {{ error }}
    </div>

    <NCard
      v-if="!loading && !error"
      class="mt-5"
      title="ЮKassa (Яндекс Касса)"
      description="Shop ID и секретный ключ из личного кабинета ЮKassa. Пока оба не заданы (или включён demo-режим), оплата не пройдёт по-настоящему."
    >
      <template #actions>
        <span
          class="rounded-full px-2.5 py-1 text-xs font-medium"
          :class="form.demo_mode ? 'bg-sun-500/15 text-sun-400' : 'bg-mint-500/15 text-mint-400'"
        >
          {{ form.demo_mode ? 'демо-оплата (деньги не двигаются)' : 'боевой режим' }}
        </span>
      </template>

      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <NInput v-model="form.shop_id" label="Shop ID" placeholder="123456" />
          <p v-if="fromEnv.shop_id" class="mt-1 text-xs text-white/40">Значение из .env — сохраните форму, чтобы закрепить его здесь.</p>
        </div>
        <div>
          <NSelect v-model="form.default_provider" label="Провайдер по умолчанию" :options="providerOptions" />
          <p v-if="fromEnv.default_provider" class="mt-1 text-xs text-white/40">Значение из .env.</p>
        </div>
        <NInput
          v-model="form.secret_key"
          type="password"
          label="Секретный ключ"
          :placeholder="secretKeySet ? '•••••••• (задан, оставьте пустым, чтобы не менять)' : 'не задан'"
        />
        <NInput
          v-model="form.webhook_secret"
          type="password"
          label="Секрет вебхука"
          :placeholder="webhookSecretSet ? '•••••••• (задан, оставьте пустым, чтобы не менять)' : 'не задан'"
        />
      </div>

      <div class="mt-4 flex flex-wrap items-center gap-3">
        <NCheckbox v-model="form.demo_mode" label="Demo-режим (локальный симулятор оплаты)" />
        <span v-if="fromEnv.demo_mode" class="text-xs text-white/40">флаг взят из .env</span>
      </div>

      <div class="mt-2 flex flex-wrap items-center gap-4">
        <button
          v-if="secretKeySet"
          type="button"
          class="text-xs text-rose-400 underline decoration-dotted hover:text-rose-300"
          @click="clearSecret('secret_key')"
        >
          Удалить секретный ключ
        </button>
        <button
          v-if="webhookSecretSet"
          type="button"
          class="text-xs text-rose-400 underline decoration-dotted hover:text-rose-300"
          @click="clearSecret('webhook_secret')"
        >
          Удалить секрет вебхука
        </button>
      </div>

      <div class="mt-5 flex items-center gap-3">
        <NButton :disabled="!isDirty || saving" @click="save">
          {{ saving ? 'Сохранение…' : 'Сохранить' }}
        </NButton>
        <span v-if="isDirty" class="text-xs text-sun-400">Есть несохранённые изменения</span>
      </div>
    </NCard>

    <p v-if="!loading && !error" class="mt-4 text-xs text-white/40">
      Секреты хранятся зашифрованными и не показываются после сохранения. Demo-режим возвращает ссылку на
      локальный симулятор оплаты — используйте его, пока нет боевых ключей.
    </p>
  </div>
</template>
