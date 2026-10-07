<script setup lang="ts">
/**
 * Почта (SMTP) — настройка исходящей почты и тестовое письмо.
 *
 * Смысл экрана: включить отправку писем без правки .env и без доступа к серверу.
 * До этого письма нельзя было запустить иначе как через SSH — для владельца
 * магазина на шаред-хостинге это был тупик.
 *
 * Пароль НИКОГДА не приходит с сервера: показывается только признак «задан».
 * Пустое поле = «не менять пароль», а не «стереть» — иначе обычное сохранение
 * формы молча ломало бы работающий SMTP.
 */
import { computed, onMounted, ref } from 'vue'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import NSelect from '@/components/ui/NSelect.vue'
import NCheckbox from '@/components/ui/NCheckbox.vue'
import { useUiStore } from '@/stores/ui'
import { get, send } from '@/lib/api'

interface MailSettingsData {
  host: string
  port: number
  username: string
  password_set: boolean
  encryption: string
  from_address: string
  from_name: string
  effective_mailer: string
  effective_host: string
}

const ui = useUiStore()

const loading = ref(true)
const saving = ref(false)
const testing = ref(false)
const error = ref<string | null>(null)
const savedSnapshot = ref('')
const passwordSet = ref(false)
const effectiveMailer = ref('')
const effectiveHost = ref('')

const form = ref({
  host: '',
  // NInput отдаёт строку — держим строкой и приводим к числу на отправке.
  port: '587',
  username: '',
  password: '',
  encryption: 'tls',
  from_address: '',
  from_name: '',
})

const testRecipient = ref('')

const ENCRYPTION_OPTIONS = [
  { value: 'tls', label: 'STARTTLS (587)' },
  { value: 'ssl', label: 'SSL/TLS (465)' },
  { value: 'none', label: 'Без шифрования' },
]

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const res = await get<{ data: MailSettingsData }>('/admin/mail/settings')
    const payload = res.data as unknown as { data?: MailSettingsData } | MailSettingsData
    const data = (payload as { data?: MailSettingsData }).data ?? (payload as MailSettingsData)
    form.value = {
      host: data?.host ?? '',
      port: String(data?.port ?? 587),
      username: data?.username ?? '',
      // Пароль с сервера не приходит — поле всегда пустое.
      password: '',
      encryption: data?.encryption || 'tls',
      from_address: data?.from_address ?? '',
      from_name: data?.from_name ?? '',
    }
    passwordSet.value = Boolean(data?.password_set)
    effectiveMailer.value = data?.effective_mailer ?? ''
    effectiveHost.value = data?.effective_host ?? ''
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
      host: form.value.host.trim(),
      port: Number(form.value.port) || 587,
      username: form.value.username.trim(),
      encryption: form.value.encryption,
      from_address: form.value.from_address.trim(),
      from_name: form.value.from_name.trim(),
    }
    // Пустая строка означает «не менять», поэтому ключ вообще не отправляем.
    if (form.value.password !== '') {
      body.password = form.value.password
    }

    const res = await send<{ data: MailSettingsData }>('/admin/mail/settings', 'PUT', body)
    const payload = res.data as unknown as { data?: MailSettingsData } | MailSettingsData
    const data = (payload as { data?: MailSettingsData }).data ?? (payload as MailSettingsData)

    form.value.password = ''
    passwordSet.value = Boolean(data?.password_set)
    effectiveMailer.value = data?.effective_mailer ?? ''
    effectiveHost.value = data?.effective_host ?? ''
    savedSnapshot.value = JSON.stringify(form.value)
    ui.notify('mint', 'Настройки сохранены', 'Письма уйдут с новыми параметрами сразу.')
  } catch (e) {
    ui.notify('rose', 'Не удалось сохранить', e instanceof Error ? e.message : String(e))
  } finally {
    saving.value = false
  }
}

async function clearPassword(): Promise<void> {
  saving.value = true
  try {
    await send('/admin/mail/settings', 'PUT', { clear_password: true })
    passwordSet.value = false
    form.value.password = ''
    ui.notify('sun', 'Пароль удалён', 'SMTP будет подключаться без пароля.')
  } catch (e) {
    ui.notify('rose', 'Не удалось удалить пароль', e instanceof Error ? e.message : String(e))
  } finally {
    saving.value = false
  }
}

async function sendTest(): Promise<void> {
  testing.value = true
  try {
    const res = await send<{ data: { ok: boolean; error: string | null } }>(
      '/admin/mail/test',
      'POST',
      { recipient: testRecipient.value.trim() },
    )
    const payload = res.data as unknown as { data?: { ok: boolean; error: string | null } }
    const data = payload.data ?? (res.data as unknown as { ok: boolean; error: string | null })
    if (data?.ok) {
      ui.notify('mint', 'Письмо отправлено', `Проверьте ящик ${testRecipient.value}.`)
    } else {
      // Ответ 200 с ошибкой транспорта: «неверный пароль SMTP» во время
      // настройки — ожидаемый результат, а не сбой сервера.
      ui.notify('rose', 'Не удалось отправить', data?.error ?? 'Неизвестная ошибка')
    }
  } catch (e) {
    ui.notify('rose', 'Не удалось отправить', e instanceof Error ? e.message : String(e))
  } finally {
    testing.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-[900px]">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold tracking-tight text-content">Почта (SMTP)</h1>
        <p class="mt-1 text-sm text-muted">
          Параметры отправки писем покупателям. Настраиваются здесь, без правки файлов на сервере.
        </p>
      </div>
    </div>

    <div v-if="error" class="surface-card mt-5 border-rose-500/30 px-4 py-3 text-sm text-rose-400">
      Не удалось загрузить настройки: {{ error }}
    </div>

    <div v-if="!loading && !error" class="surface-card mt-5 p-4 sm:p-5">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm font-semibold text-content">Сервер исходящей почты</p>
        <span
          class="rounded-full px-2.5 py-1 text-xs font-medium"
          :class="effectiveMailer === 'smtp' ? 'bg-mint-500/15 text-mint-400' : 'bg-sun-500/15 text-sun-400'"
        >
          {{ effectiveMailer === 'smtp' ? `SMTP: ${effectiveHost || 'не задан'}` : `транспорт: ${effectiveMailer || '—'}` }}
        </span>
      </div>

      <p class="mt-3 text-xs text-muted">
        Если хост не заполнен, используется транспорт из конфигурации сервера. Заполните хост,
        чтобы переключить отправку на SMTP с этих параметров.
      </p>

      <div class="mt-4 grid gap-4 sm:grid-cols-2">
        <NInput v-model="form.host" label="Хост SMTP" placeholder="smtp.yandex.ru" />
        <NInput v-model="form.port" type="number" label="Порт" placeholder="587" />
        <NInput v-model="form.username" label="Пользователь" placeholder="noreply@example.ru" />
        <NInput
          v-model="form.password"
          type="password"
          label="Пароль"
          :placeholder="passwordSet ? '•••••••• (задан, оставьте пустым, чтобы не менять)' : 'не задан'"
        />
        <NSelect v-model="form.encryption" label="Шифрование" :options="ENCRYPTION_OPTIONS" />
        <NInput v-model="form.from_address" label="Адрес отправителя" placeholder="noreply@example.ru" />
        <NInput v-model="form.from_name" label="Имя отправителя" placeholder="NABILET" />
      </div>

      <div class="mt-4 flex flex-wrap items-center gap-3">
        <NCheckbox
          v-model="passwordSet"
          :disabled="true"
          label="Пароль задан"
        />
        <button
          v-if="passwordSet"
          type="button"
          class="text-xs text-rose-400 underline decoration-dotted hover:text-rose-300"
          @click="clearPassword"
        >
          Удалить пароль
        </button>
      </div>

      <div class="mt-5 flex items-center gap-3">
        <NButton :disabled="!isDirty || saving" @click="save">
          {{ saving ? 'Сохранение…' : 'Сохранить' }}
        </NButton>
        <span v-if="isDirty" class="text-xs text-sun-400">Есть несохранённые изменения</span>
      </div>
    </div>

    <!-- Тестовое письмо -->
    <div v-if="!loading && !error" class="surface-card mt-5 p-4 sm:p-5">
      <p class="text-sm font-semibold text-content">Проверка</p>
      <p class="mt-2 text-xs text-muted">
        Отправит одно письмо на указанный адрес текущими настройками. Сохраните изменения перед проверкой.
      </p>
      <div class="mt-4 flex flex-wrap items-end gap-3">
        <div class="min-w-[240px] flex-1">
          <NInput v-model="testRecipient" type="email" label="Куда отправить" placeholder="you@example.ru" />
        </div>
        <NButton
          variant="secondary"
          :disabled="testing || !testRecipient.trim()"
          @click="sendTest"
        >
          {{ testing ? 'Отправка…' : 'Отправить тест' }}
        </NButton>
      </div>
    </div>
  </div>
</template>
