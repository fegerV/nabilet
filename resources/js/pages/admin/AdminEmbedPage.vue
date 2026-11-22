<script setup lang="ts">
/**
 * Встраивание (embed) в чужие сайты: ключи, whitelist доменов, генератор кода.
 *
 * Три секции — это весь жизненный цикл партнёра: выпустил ключ (токен видел
 * один раз), добавил свой домен в whitelist, скопировал готовый `<iframe>`
 * со слушателем моста к себе на страницу.
 *
 * Генератор кода показывает сниппет только СРАЗУ после выпуска ключа. Токен
 * живёт на странице ровно один показ — дальше в базе только sha256, и «показать
 * ещё раз» физически нечего. Это то же правило, что у секретов вебхуков:
 * повторный показ значил бы держать креденшл в интерфейсе, откуда его унесёт
 * скриншот.
 *
 * ВИДЖЕТ ПОЛНОСТЬЮ РАБОТАЕТ: рамка грузит витрину с `embed_token` в query, а
 * денежный контур (карта зала, холды, заказ, платёж) идёт через `/api/v1/embed/*`.
 * Ключ и whitelist доменов проверяются на каждом запросе; CSP при этом
 * разрешает встраивание только на активных доменах организации.
 */
import { computed, onMounted, ref } from 'vue'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import { get, send, ApiError } from '@/lib/api'
import { usePersistedRef } from '@/lib/usePersistedRef'
import { buildEmbedUrl, buildFullSnippet } from '@/lib/embed-code'

interface EmbedKey {
  id: number
  public_id: string
  name: string
  key_prefix: string
  expires_at: string
  revoked_at: string | null
}

interface EmbedDomain {
  id: number
  domain: string
  active: boolean
}

interface OrgRow {
  id: number
  name: string
}

interface EventRow {
  id: number
  slug: string
  title: string
  status?: string
}


/** Ответ может прийти как { data: [...] }, так и развёрнутым массивом. */
function rows<T>(payload: unknown): T[] {
  if (Array.isArray(payload)) return payload as T[]
  const inner = (payload as { data?: unknown } | null)?.data
  return Array.isArray(inner) ? (inner as T[]) : []
}

const loading = ref(true)
const keysError = ref('')
const domainsError = ref('')

const keys = ref<EmbedKey[]>([])
const domains = ref<EmbedDomain[]>([])
const orgs = ref<OrgRow[]>([])
const orgId = ref<number | null>(null)

/** Текущая организация в шапке хранится ИМЕНЕМ (`admin.org`); ищем её id. */
const currentOrgName = usePersistedRef('admin.org', '')

const keyForm = ref({ name: '', expiresAt: '' })
const keyFormErrors = ref<{ name?: string; expires_at?: string }>({})
const issuing = ref(false)

const domainInput = ref('')
const addingDomain = ref(false)

/** Токен живёт только между выпускoм и перезагрузкой; второй раз не показывается. */
const issuedToken = ref('')
const issuedKeyName = ref('')
/** Путь внутри витрины: пусто — каталог, `/event/slug` — конкретное событие. */
const widgetPath = ref('')
const events = ref<EventRow[]>([])
const copying = ref(false)
const domainBusyId = ref<number | null>(null)

/** URL рамки для «открыть в новой вкладке» — тот же, что в сниппете. */
const widgetUrl = computed(() =>
  issuedToken.value === ''
    ? ''
    : buildEmbedUrl({
        storefrontOrigin: window.location.origin,
        path: widgetPath.value,
        token: issuedToken.value,
      }),
)
const copied = ref(false)

async function load(): Promise<void> {
  loading.value = true
  keysError.value = ''
  // Отказ справочника организаций не должен прятать уже загруженные ключи и
  // домены: раньше один Promise.all обнулял всё и блокировал выпуск.
  const [keysRes, domainsRes, orgsRes, eventsRes] = await Promise.allSettled([
    get<unknown>('/admin/embed/keys'),
    get<unknown>('/admin/embed/domains'),
    get<unknown>('/organizations?limit=50'),
    get<unknown>('/events?status=published&per_page=100'),
  ])

  if (keysRes.status === 'fulfilled') keys.value = rows<EmbedKey>(keysRes.value.data as unknown[])
  else keysError.value = String(keysRes.reason?.message ?? keysRes.reason)

  if (domainsRes.status === 'fulfilled') domains.value = rows<EmbedDomain>(domainsRes.value.data as unknown[])
  else domainsError.value = String(domainsRes.reason?.message ?? domainsRes.reason)

  if (orgsRes.status === 'fulfilled') {
    orgs.value = rows<OrgRow>(orgsRes.value.data as unknown[])
    const preferred = orgs.value.find((o) => o.name === currentOrgName.value)
    orgId.value = preferred?.id ?? orgs.value[0]?.id ?? null
  } else {
    orgId.value = null
  }

  if (eventsRes.status === 'fulfilled') {
    events.value = rows<EventRow>(eventsRes.value.data as unknown[])
  }
  loading.value = false
}

/** Значение по умолчанию для срока: через год, в формате datetime-local. */
function inOneYear(): string {
  const d = new Date()
  d.setFullYear(d.getFullYear() + 1)
  const pad = (n: number): string => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

async function issueKey(): Promise<void> {
  if (orgId.value === null) return
  issuing.value = true
  keyFormErrors.value = {}
  keysError.value = ''
  try {
    const res = await send<{ key: EmbedKey; token: string }>('/admin/embed/keys', 'POST', {
      organization_id: orgId.value,
      name: keyForm.value.name.trim(),
      expires_at: keyForm.value.expiresAt,
    })
    // Единственный показ. Сразу закладываем в сниппет — так админ не успеет
    // потерять токен между выпуском и установкой виджета.
    issuedToken.value = res.data.token
    issuedKeyName.value = res.data.key.name
    keyForm.value.name = ''
    keyForm.value.expiresAt = inOneYear()
    copied.value = false
    await loadKeysOnly()
  } catch (e) {
    if (e instanceof ApiError && e.details) {
      // Политика может отказать и на organization_id / scopes — такие ошибки
      // раньше терялись и показывались общим текстом без подсветки поля.
      keyFormErrors.value = {
        name: e.details.name?.[0] ?? e.details.organization_id?.[0] ?? e.details.scopes?.[0],
        expires_at: e.details.expires_at?.[0],
      }
      if (!keyFormErrors.value.name && !keyFormErrors.value.expires_at) {
        keysError.value = e.message
      }
    } else {
      keysError.value = e instanceof Error ? e.message : String(e)
    }
  }
  issuing.value = false
}

/** Список ключей обновляется отдельно от организаций: выпуск не должен сбивать выбор. */
async function loadKeysOnly(): Promise<void> {
  try {
    const res = await get<unknown>('/admin/embed/keys')
    keys.value = rows<EmbedKey>(res.data as unknown[])
  } catch {
    // Список догрузит следующий полный `load()`; выпуск уже успешен.
  }
}

async function revoke(k: EmbedKey): Promise<void> {
  if (
    !window.confirm(
      `Отозвать ключ «${k.name}»? Виджеты, работающие на нём, закроются сразу. Отзыв необратим.`,
    )
  ) {
    return
  }
  keysError.value = ''
  try {
    await send(`/admin/embed/keys/${k.public_id}`, 'PATCH', { revoked: true })
    await loadKeysOnly()
  } catch (e) {
    keysError.value = e instanceof Error ? e.message : String(e)
  }
}

async function addDomain(): Promise<void> {
  if (orgId.value === null) return
  addingDomain.value = true
  domainsError.value = ''
  try {
    await send('/admin/embed/domains', 'POST', {
      organization_id: orgId.value,
      domain: domainInput.value.trim(),
      active: true,
    })
    domainInput.value = ''
    const res = await get<unknown>('/admin/embed/domains')
    domains.value = rows<EmbedDomain>(res.data as unknown[])
  } catch (e) {
    if (e instanceof ApiError && e.code === 'EMBED_DOMAIN_ALREADY_LISTED') {
      domainsError.value = 'Домен уже в списке.'
    } else if (e instanceof ApiError && e.details?.domain?.length) {
      domainsError.value = e.details.domain[0]
    } else {
      domainsError.value = e instanceof Error ? e.message : String(e)
    }
  }
  addingDomain.value = false
}

async function toggleDomain(d: EmbedDomain): Promise<void> {
  if (domainBusyId.value !== null) return
  domainBusyId.value = d.id
  domainsError.value = ''
  try {
    await send(`/admin/embed/domains/${d.id}`, 'PATCH', { active: !d.active })
    d.active = !d.active
  } catch (e) {
    domainsError.value = e instanceof Error ? e.message : String(e)
  } finally {
    domainBusyId.value = null
  }
}

async function removeDomain(d: EmbedDomain): Promise<void> {
  if (!window.confirm(`Удалить домен «${d.domain}» из whitelist? Виджет на нём перестанет открываться.`)) {
    return
  }
  domainBusyId.value = d.id
  domainsError.value = ''
  try {
    await send(`/admin/embed/domains/${d.id}`, 'DELETE')
    domains.value = domains.value.filter((x) => x.id !== d.id)
  } catch (e) {
    domainsError.value = e instanceof Error ? e.message : String(e)
  } finally {
    domainBusyId.value = null
  }
}

/**
 * Готовый код для копирования. Существует только сразу после выпуска ключа:
 * токен есть ровно один показ, и сниппет без токена был бы имитацией.
 */
const snippet = computed(() => {
  if (issuedToken.value === '') return ''
  return buildFullSnippet({
    // Витрина живёт на том же origin, что и админка (SPA внутри Laravel).
    storefrontOrigin: window.location.origin,
    path: widgetPath.value,
    token: issuedToken.value,
    title: 'Билеты NABILET',
  })
})

async function copySnippet(): Promise<void> {
  if (snippet.value === '') return
  copying.value = true
  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(snippet.value)
    } else {
      // HTTP-стенд или запрет clipboard API: старый надёжный путь через
      // скрытый textarea. Раньше здесь был unhandled rejection и «не скопировано».
      const area = document.createElement('textarea')
      area.value = snippet.value
      area.style.position = 'fixed'
      area.style.opacity = '0'
      document.body.appendChild(area)
      area.select()
      document.execCommand('copy')
      area.remove()
    }
    copied.value = true
  } catch (e) {
    keysError.value = `Не удалось скопировать: ${e instanceof Error ? e.message : String(e)}. Выделите код вручную.`
  } finally {
    copying.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-4">
    <header>
      <h1 class="text-xl font-semibold">Встраивание</h1>
      <p class="mt-1 text-sm text-muted">
        Виджет для чужих сайтов: рамка с каталогом или конкретным событием,
        продажа через <code>/api/v1/embed/*</code> и postMessage-канал
        <code>nabilet-embed</code> — корзина, заказ, оплата, высота рамки.
        Ключ и домены проверяются на каждом запросе.
      </p>
    </header>

    <!-- Готовый код: показывается сразу после выпуска ключа -->
    <section v-if="issuedToken" class="space-y-3 rounded-xl border border-line p-4">
      <h2 class="text-base font-semibold">Готовый код для «{{ issuedKeyName }}»</h2>
      <div class="grid gap-3 sm:grid-cols-2">
        <select
          v-model="widgetPath"
          class="w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm"
        >
          <option value="">Каталог афиши</option>
          <option v-for="ev in events" :key="ev.id" :value="`/event/${ev.slug}`">
            {{ ev.title }}
          </option>
        </select>
        <NInput
          v-model="widgetPath"
          label="Или путь вручную"
          hint="Например /event/my-event"
          placeholder="/event/my-event"
        />
      </div>
      <pre class="overflow-x-auto rounded-lg bg-black/20 p-3 text-xs leading-5">{{ snippet }}</pre>
      <div class="flex flex-wrap items-center gap-3">
        <NButton :loading="copying" @click="copySnippet">{{ copied ? 'Скопировано' : 'Копировать код' }}</NButton>
        <a
          v-if="widgetUrl"
          :href="widgetUrl"
          target="_blank"
          rel="noopener noreferrer"
          class="text-sm text-brand-500 hover:underline"
        >Открыть витрину виджета ↗</a>
        <p class="text-xs text-muted">
          Вставьте блок на страницу партнёра: он подгонит высоту рамки и будет
          получать события корзины/заказа. Токен показывается один раз.
        </p>
      </div>
      <p class="text-xs text-muted">
        Токен в коде уже подставлен. Отзыв ключа или удаление домена из
        whitelist закроют все установленные виджеты.
      </p>
    </section>

    <p v-if="keysError" class="rounded-lg bg-rose-500/10 px-3 py-2 text-sm text-rose-400">{{ keysError }}</p>
    <div v-if="loading" class="text-sm text-muted">Загружаем ключи, домены и события…</div>

    <!-- Выпуск ключа -->
    <section class="space-y-3 rounded-xl border border-line p-4">
      <h2 class="text-base font-semibold">Выпуск ключа</h2>
      <div>
        <span class="mb-1 block text-sm font-medium">Организация</span>
        <!-- Владельца определяет сервер из вашей сессии: выпадающий список
             создавал бы ложное ощущение, что ключ можно выпустить для другой
             организации. Оставляем только read-only подсказку. -->
        <p class="rounded-lg border border-line bg-canvas px-3 py-2 text-sm">
          {{ orgs.find((o) => o.id === orgId)?.name ?? 'Текущая организация' }}
        </p>
        <p class="mt-1 text-xs text-muted">
          Ключ и домены всегда привязаны к организации вашей сессии.
        </p>
      </div>
      <NInput
        v-model="keyForm.name"
        label="Название"
        hint="Например, адрес страницы партнёра, где стоит виджет"
        placeholder="Мой сайт — главная"
        :error="keyFormErrors.name"
      />
      <NInput
        v-model="keyForm.expiresAt"
        label="Действует до"
        type="datetime-local"
        hint="Бессрочный ключ политика не пропустит: креденшл без срока нельзя вывести из обращения"
        :error="keyFormErrors.expires_at"
      />
      <NButton :loading="issuing" :disabled="orgId === null || !keyForm.name.trim() || !keyForm.expiresAt" @click="issueKey">
        Выпустить ключ
      </NButton>
      <p v-if="orgs.length === 0" class="text-xs text-muted">
        Список организаций недоступен — выпуск невозможен.
      </p>
    </section>

    <!-- Список ключей -->
    <section class="space-y-2">
      <h2 class="text-base font-semibold">Ключи</h2>
      <p v-if="keys.length === 0" class="text-sm text-muted">Ключей пока нет.</p>
      <div v-for="k in keys" :key="k.id" class="flex flex-wrap items-start justify-between gap-2 rounded-xl border border-line p-4">
        <div>
          <div class="font-medium">{{ k.name }}</div>
          <div class="text-xs text-muted">
            {{ k.key_prefix }}… · действует до {{ new Date(k.expires_at).toLocaleString() }} ·
            {{ k.revoked_at ? `отозван ${new Date(k.revoked_at).toLocaleString()}` : 'активен' }}
          </div>
        </div>
        <NButton v-if="!k.revoked_at" variant="danger" size="sm" @click="revoke(k)">Отозвать</NButton>
      </div>
    </section>

    <!-- Whitelist доменов -->
    <section class="space-y-3 rounded-xl border border-line p-4">
      <h2 class="text-base font-semibold">Домены</h2>
      <p class="text-sm text-muted">
        С домена вне списка (или без ключа) embed-API отвечает отказом:
        происхождение рамки проверяется на каждом запросе.
      </p>

      <p v-if="domainsError" class="rounded-lg bg-rose-500/10 px-3 py-2 text-sm text-rose-400">{{ domainsError }}</p>

      <div class="flex flex-wrap items-end gap-3">
        <NInput v-model="domainInput" label="Домен" placeholder="partner.example" class="max-w-xs" />
        <NButton :loading="addingDomain" :disabled="!domainInput.trim() || orgId === null" @click="addDomain">
          Добавить
        </NButton>
      </div>

      <p v-if="domains.length === 0" class="text-sm text-muted">Доменов пока нет.</p>
      <div v-for="d in domains" :key="d.id" class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-line p-4">
        <div>
          <div class="font-medium">{{ d.domain }}</div>
          <div class="text-xs text-muted">{{ d.active ? 'включён' : 'выключен' }}</div>
        </div>
        <div class="flex gap-2">
          <NButton variant="secondary" size="sm" :loading="domainBusyId === d.id" :disabled="domainBusyId !== null" @click="toggleDomain(d)">
            {{ d.active ? 'Выключить' : 'Включить' }}
          </NButton>
          <NButton variant="danger" size="sm" :disabled="domainBusyId !== null" @click="removeDomain(d)">Удалить</NButton>
        </div>
      </div>
    </section>
  </div>
</template>
