<script setup lang="ts">
/**
 * Matrix (Element X) — административный канал уведомлений.
 *
 * Смысл экрана: включить ленту событий магазина в комнату Matrix без правки
 * .env и без доступа к серверу. Токен доступа к Synapse — секрет: с сервера он
 * НЕ приходит, показывается только признак «задан». Пустое поле = «не менять»,
 * стереть — отдельная кнопка, чтобы обычное сохранение формы не рвало канал.
 *
 * Сообщения адресованы ТОЛЬКО сотрудникам: они уходят в настроенную комнату,
 * которую читают администраторы. Покупателям этот канал не пишет ничего.
 */
import { computed, onMounted, ref } from 'vue'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import NCheckbox from '@/components/ui/NCheckbox.vue'
import NCard from '@/components/ui/NCard.vue'
import NPageHeader from '@/components/ui/NPageHeader.vue'
import { useUiStore } from '@/stores/ui'
import { get, send } from '@/lib/api'

interface MatrixSettingsData {
  homeserver_url: string
  homeserver_url_from_env: boolean
  room_id: string
  room_id_from_env: boolean
  access_token_set: boolean
  enabled: boolean
  enabled_from_env: boolean
  notify_orders: boolean
  notify_orders_from_env: boolean
  configured: boolean
}

interface TestResult {
  ok: boolean
  error: string | null
}

const ui = useUiStore()

const loading = ref(true)
const saving = ref(false)
const testing = ref(false)
const error = ref<string | null>(null)
const testResult = ref<TestResult | null>(null)
const savedSnapshot = ref('')

const form = ref({
  homeserver_url: '',
  room_id: '',
  access_token: '',
  enabled: false,
  notify_orders: true,
})

const fromEnv = ref({
  homeserver_url: false,
  room_id: false,
  enabled: false,
  notify_orders: false,
})

const tokenSet = ref(false)
const configured = ref(false)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const res = await get<MatrixSettingsData>('/admin/matrix/settings')
    const data = res.data
    form.value = {
      homeserver_url: data?.homeserver_url ?? '',
      room_id: data?.room_id ?? '',
      // Токен с сервера не приходит — поле всегда пустое.
      access_token: '',
      enabled: Boolean(data?.enabled),
      notify_orders: data?.notify_orders ?? true,
    }
    fromEnv.value = {
      homeserver_url: Boolean(data?.homeserver_url_from_env),
      room_id: Boolean(data?.room_id_from_env),
      enabled: Boolean(data?.enabled_from_env),
      notify_orders: Boolean(data?.notify_orders_from_env),
    }
    tokenSet.value = Boolean(data?.access_token_set)
    configured.value = Boolean(data?.configured)
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
  testResult.value = null
  try {
    const body: Record<string, unknown> = {
      homeserver_url: form.value.homeserver_url.trim(),
      room_id: form.value.room_id.trim(),
      enabled: form.value.enabled,
      notify_orders: form.value.notify_orders,
    }
    // Пустая строка = «не менять», поэтому токен вообще не отправляем.
    if (form.value.access_token !== '') body.access_token = form.value.access_token

    const res = await send<MatrixSettingsData>('/admin/matrix/settings', 'PUT', body)
    const data = res.data

    form.value.access_token = ''
    tokenSet.value = Boolean(data?.access_token_set)
    configured.value = Boolean(data?.configured)
    savedSnapshot.value = JSON.stringify(form.value)
    ui.notify('mint', 'Настройки сохранены', 'Канал Matrix применит их сразу.')
  } catch (e) {
    ui.notify('rose', 'Не удалось сохранить', e instanceof Error ? e.message : String(e))
  } finally {
    saving.value = false
  }
}

async function clearToken(): Promise<void> {
  saving.value = true
  try {
    const res = await send<MatrixSettingsData>('/admin/matrix/settings', 'PUT', { clear_access_token: true })
    tokenSet.value = Boolean(res.data?.access_token_set)
    configured.value = Boolean(res.data?.configured)
    form.value.access_token = ''
    ui.notify('sun', 'Токен удалён', 'Канал перестанет отправлять сообщения, пока токен не задан заново.')
  } catch (e) {
    ui.notify('rose', 'Не удалось удалить', e instanceof Error ? e.message : String(e))
  } finally {
    saving.value = false
  }
}

async function runTest(): Promise<void> {
  testing.value = true
  testResult.value = null
  try {
    const res = await send<TestResult>('/admin/matrix/test', 'POST')
    testResult.value = res.data
    if (res.data?.ok) ui.notify('mint', 'Сообщение отправлено', 'Проверьте комнату в Element X.')
  } catch (e) {
    testResult.value = { ok: false, error: e instanceof Error ? e.message : String(e) }
  } finally {
    testing.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-[900px]">
    <NPageHeader
      title="Matrix (Element X)"
      subtitle="Административные уведомления в комнату Matrix через удалённый Synapse. Сообщения видят только сотрудники — покупателям канал ничего не отправляет."
    />

    <div v-if="error" class="surface-card mt-5 border-rose-500/30 px-4 py-3 text-sm text-rose-400">
      Не удалось загрузить настройки: {{ error }}
    </div>

    <NCard
      v-if="!loading && !error"
      class="mt-5"
      title="Подключение к Synapse"
      description="Адрес homeserver'а, ID комнаты и токен доступа. Пока все три не заданы, отправка невозможна — тест-сообщение сообщит, чего не хватает."
    >
      <template #actions>
        <span
          class="rounded-full px-2.5 py-1 text-xs font-medium"
          :class="form.enabled && configured ? 'bg-mint-500/15 text-mint-400' : 'bg-sun-500/15 text-sun-400'"
        >
          {{ !configured ? 'не настроено' : form.enabled ? 'канал включён' : 'канал выключен' }}
        </span>
      </template>

      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <NInput v-model="form.homeserver_url" label="Homeserver (Synapse)" placeholder="https://matrix.example.org" />
          <p v-if="fromEnv.homeserver_url" class="mt-1 text-xs text-white/40">Значение из .env — сохраните форму, чтобы закрепить его здесь.</p>
        </div>
        <div>
          <NInput v-model="form.room_id" label="ID комнаты" placeholder="!abcdef:matrix.example.org" />
          <p v-if="fromEnv.room_id" class="mt-1 text-xs text-white/40">Значение из .env.</p>
        </div>
        <div class="sm:col-span-2">
          <NInput
            v-model="form.access_token"
            type="password"
            label="Токен доступа (access token)"
            :placeholder="tokenSet ? '•••••••• (задан, оставьте пустым, чтобы не менять)' : 'не задан'"
          />
          <p class="mt-1 text-xs text-white/40">
            Токен бота Synapse. Нужен идентификатор комнаты (начинается с «!»), а не её алиас («#name:server»).
          </p>
        </div>
      </div>

      <div class="mt-4 flex flex-wrap items-center gap-x-6 gap-y-3">
        <NCheckbox v-model="form.enabled" label="Включить канал Matrix" />
        <NCheckbox v-model="form.notify_orders" label="Уведомлять об оплаченных заказах" />
      </div>

      <div v-if="tokenSet" class="mt-2">
        <button
          type="button"
          class="text-xs text-rose-400 underline decoration-dotted hover:text-rose-300"
          @click="clearToken"
        >
          Удалить токен доступа
        </button>
      </div>

      <div class="mt-5 flex flex-wrap items-center gap-3">
        <NButton :disabled="!isDirty || saving" @click="save">
          {{ saving ? 'Сохранение…' : 'Сохранить' }}
        </NButton>
        <NButton variant="ghost" :disabled="!configured || testing" @click="runTest">
          {{ testing ? 'Отправка…' : 'Отправить тест-сообщение' }}
        </NButton>
        <span v-if="isDirty" class="text-xs text-sun-400">Есть несохранённые изменения</span>
      </div>

      <div
        v-if="testResult"
        class="mt-4 rounded-lg px-3 py-2 text-sm"
        :class="testResult.ok ? 'bg-mint-500/10 text-mint-300' : 'bg-rose-500/10 text-rose-300'"
      >
        <template v-if="testResult.ok">Тестовое сообщение ушло в комнату. Проверьте Element X.</template>
        <template v-else>Не удалось отправить: {{ testResult.error }}</template>
      </div>
    </NCard>

    <p v-if="!loading && !error" class="mt-4 text-xs text-white/40">
      Токен хранится зашифрованным и не показывается после сохранения. Сообщения идут в одну комнату
      (её читают администраторы) — персональных сообщений покупателям этот канал не отправляет.
    </p>
  </div>
</template>
