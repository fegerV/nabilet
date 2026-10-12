<script setup lang="ts">
/**
 * Регистрация покупателя.
 *
 * ПОЧЕМУ ЭТО НЕ ОБЯЗАТЕЛЬНЫЙ ШАГ ПЕРЕД ПОКУПКОЙ
 *   Витрина гостевая, и требование зарегистрироваться до покупки — известная
 *   причина брошенных корзин. Поэтому основной путь такой: покупатель платит как
 *   гость, а аккаунт создаётся сам из имени и адреса заказа. Эта страница — для
 *   тех, кто хочет завести аккаунт заранее (и получить доступ к лояльности,
 *   которую иначе не к кому привязать).
 *
 * ОШИБКИ ПОЛЕЙ БЕРУТСЯ ИЗ КОНВЕРТА §66. `ApiError.details` — это карта
 * «поле → сообщения», собранная из `error.details.fields`; показываем её под
 * конкретным инпутом, а не общей строкой «что-то пошло не так». Занятый адрес —
 * самое частое здесь, и он обязан быть виден у поля `email`.
 */
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import { customerAuthMessage, registerCustomer } from '@/lib/customer'
import { ApiError } from '@/lib/api'

const router = useRouter()

const firstName = ref('')
const lastName = ref('')
const email = ref('')
const phone = ref('')
const password = ref('')
const confirmation = ref('')

const loading = ref(false)
const error = ref<string | null>(null)
const fields = ref<Record<string, string>>({})

/** Минимум политики паролей (`PasswordRules::MIN_LENGTH` на сервере). */
const MIN_PASSWORD = 12

function messageOf(key: string): string | undefined {
  return fields.value[key]
}

async function submit(): Promise<void> {
  if (loading.value) return
  error.value = null
  fields.value = {}

  // Проверяем только то, что сервер не сможет объяснить лучше. Политику
  // сложности не дублируем — она одна на бэкенде (`PasswordRules`), и вторая
  // копия здесь разошлась бы с ней при первом же изменении.
  if (password.value !== confirmation.value) {
    fields.value = { password_confirmation: 'Пароли не совпадают.' }
    return
  }

  loading.value = true
  try {
    await registerCustomer({
      email: email.value,
      password: password.value,
      password_confirmation: confirmation.value,
      first_name: firstName.value,
      last_name: lastName.value,
      phone: phone.value,
    })
    router.push('/account')
  } catch (e) {
    if (e instanceof ApiError && e.details) {
      const flat: Record<string, string> = {}
      for (const [field, messages] of Object.entries(e.details)) {
        if (Array.isArray(messages) && messages.length > 0) flat[field] = String(messages[0])
      }
      fields.value = flat
      if (Object.keys(flat).length === 0) error.value = customerAuthMessage(e)
    } else {
      error.value = customerAuthMessage(e)
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-md px-4 py-10 sm:px-6">
    <h1 class="font-display text-2xl font-bold tracking-tight text-content">Регистрация</h1>
    <p class="mt-1.5 text-sm text-muted">
      Аккаунт связывает билеты с вами, а не с браузером.
    </p>

    <form class="surface-card mt-6 p-6" aria-label="Регистрация покупателя" @submit.prevent="submit">
      <div v-if="error" class="mb-4 rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2.5 text-sm text-rose-400">
        {{ error }}
      </div>

      <div class="space-y-4">
        <div class="grid gap-4 sm:grid-cols-2">
          <NInput
            v-model="firstName"
            label="Имя"
            placeholder="Иван"
            autocomplete="given-name"
            required
            :error="messageOf('first_name')"
          />
          <NInput
            v-model="lastName"
            label="Фамилия"
            placeholder="Петров"
            autocomplete="family-name"
            required
            :error="messageOf('last_name')"
          />
        </div>

        <NInput
          v-model="email"
          type="email"
          label="E-mail"
          placeholder="ivan@example.com"
          autocomplete="email"
          inputmode="email"
          required
          icon="✉"
          :error="messageOf('email')"
          hint="На него придут билеты"
        />

        <NInput
          v-model="phone"
          type="tel"
          label="Телефон"
          placeholder="+7 900 000-00-00"
          autocomplete="tel"
          inputmode="tel"
          :error="messageOf('phone')"
          hint="Необязательно"
        />

        <NInput
          v-model="password"
          type="password"
          label="Пароль"
          placeholder="••••••••••••"
          autocomplete="new-password"
          required
          :error="messageOf('password')"
          :hint="`Минимум ${MIN_PASSWORD} символов: заглавные и строчные буквы, цифра и знак`"
        />

        <NInput
          v-model="confirmation"
          type="password"
          label="Пароль ещё раз"
          placeholder="••••••••••••"
          autocomplete="new-password"
          required
          :error="messageOf('password_confirmation')"
        />

        <NButton type="submit" variant="primary" block size="lg" :loading="loading">Создать аккаунт</NButton>
      </div>
    </form>

    <p class="mt-4 text-sm text-muted">
      Уже есть аккаунт?
      <RouterLink to="/login" class="text-brand-400 transition-colors hover:text-brand-300">Войти</RouterLink>
    </p>
  </div>
</template>
