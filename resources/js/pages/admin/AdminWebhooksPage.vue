<script setup lang="ts">
/**
 * Исходящие вебхуки.
 *
 * Здесь организация подписывает свой endpoint на события заказа
 * (`order.paid`, `order.cancelled`, …). Доставка видна по попыткам: если
 * партнёр говорит «мы ничего не получили», ответ ищется в deliveries, а не в
 * логах.
 *
 * Секрет показывается ОДИН раз, сразу после создания — дальше он хранится
 * только в зашифрованном виде и из админки не читается. Показывать его
 * повторно значит держать ключ подписи в интерфейсе, откуда его вынесут
 * скриншотом.
 */
import { onMounted, ref } from 'vue'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import NCheckbox from '@/components/ui/NCheckbox.vue'
import { get, send } from '@/lib/api'

interface Subscription {
  id: number
  public_id: string
  url: string
  events: string[]
  active: boolean
  retry_limit: number
}

interface Delivery {
  id: number
  delivery_id: string
  event_name: string
  status_code: number | null
  attempt: number
  error_message: string | null
  delivered_at: string | null
  next_retry_at: string | null
  created_at: string | null
}

const subscriptions = ref<Subscription[]>([])
const availableEvents = ref<string[]>([])
const deliveries = ref<Delivery[]>([])
const deliveriesFor = ref<number | null>(null)

const form = ref({ url: '', events: [] as string[], active: true, retry_limit: 10 })
const createdSecret = ref<string | null>(null)

const loading = ref(true)
const saving = ref(false)
const error = ref<string | null>(null)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const res = await get<{ subscriptions: Subscription[]; events: string[] }>('/webhook-subscriptions')
    subscriptions.value = res.data.subscriptions ?? []
    availableEvents.value = res.data.events ?? []
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    loading.value = false
  }
}

function toggleEvent(name: string): void {
  const index = form.value.events.indexOf(name)
  if (index === -1) form.value.events.push(name)
  else form.value.events.splice(index, 1)
}

async function create(): Promise<void> {
  saving.value = true
  error.value = null
  createdSecret.value = null
  try {
    const res = await send<Subscription & { secret: string }>(
      '/webhook-subscriptions',
      'POST',
      form.value,
    )
    createdSecret.value = res.data.secret
    form.value = { url: '', events: [], active: true, retry_limit: 10 }
    await load()
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    saving.value = false
  }
}

async function toggleActive(sub: Subscription): Promise<void> {
  error.value = null
  try {
    await send(`/webhook-subscriptions/${sub.id}`, 'PUT', { active: !sub.active })
    await load()
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  }
}

async function remove(sub: Subscription): Promise<void> {
  error.value = null
  try {
    await send(`/webhook-subscriptions/${sub.id}`, 'DELETE')
    if (deliveriesFor.value === sub.id) {
      deliveries.value = []
      deliveriesFor.value = null
    }
    await load()
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  }
}

async function showDeliveries(sub: Subscription): Promise<void> {
  error.value = null
  try {
    const res = await get<Delivery[]>(`/webhook-subscriptions/${sub.id}/deliveries`)
    deliveries.value = res.data ?? []
    deliveriesFor.value = sub.id
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  }
}

function outcome(d: Delivery): string {
  if (d.delivered_at) return 'доставлено'
  if (d.next_retry_at) return `повтор в ${d.next_retry_at}`
  return 'прекращено'
}

onMounted(load)
</script>

<template>
  <div class="space-y-4">
    <header>
      <h1 class="text-xl font-semibold">Исходящие вебхуки</h1>
      <p class="mt-1 text-sm text-muted">
        NABILET отправляет POST с подписью
        <code>X-Nabilet-Signature</code> (HMAC-SHA256 по телу) на каждый
        подписанный адрес. Повтор — с экспоненциальной паузой; 4xx не
        повторяется.
      </p>
    </header>

    <p v-if="error" class="rounded-lg bg-rose-500/10 px-3 py-2 text-sm text-rose-400">{{ error }}</p>

    <div v-if="createdSecret" class="rounded-lg bg-mint-500/10 px-3 py-2 text-sm text-mint-400">
      <b>Секрет подписи (показывается один раз):</b>
      <code class="block mt-1 break-all">{{ createdSecret }}</code>
    </div>

    <section class="space-y-3 rounded-xl border border-line p-4">
      <h2 class="text-base font-semibold">Новая подписка</h2>
      <NInput v-model="form.url" label="URL endpoint" placeholder="https://example.com/hook" />
      <div>
        <span class="mb-1 block text-sm font-medium">События</span>
        <div class="flex flex-wrap gap-3">
          <NCheckbox
            v-for="name in availableEvents"
            :key="name"
            :model-value="form.events.includes(name)"
            :label="name"
            @update:model-value="toggleEvent(name)"
          />
        </div>
      </div>
      <NCheckbox v-model="form.active" label="Подписка активна" />
      <NButton :loading="saving" :disabled="!form.url || form.events.length === 0" @click="create">
        Создать
      </NButton>
    </section>

    <div v-if="loading" class="text-sm text-muted">Загрузка…</div>

    <section v-else class="space-y-2">
      <h2 class="text-base font-semibold">Подписки</h2>
      <p v-if="subscriptions.length === 0" class="text-sm text-muted">
        Подписок пока нет.
      </p>
      <div
        v-for="sub in subscriptions"
        :key="sub.id"
        class="space-y-2 rounded-xl border border-line p-4"
      >
        <div class="flex flex-wrap items-start justify-between gap-2">
          <div>
            <div class="font-medium break-all">{{ sub.url }}</div>
            <div class="text-xs text-muted">
              {{ sub.events.join(', ') }} · повторов до {{ sub.retry_limit }} ·
              {{ sub.active ? 'активна' : 'выключена' }}
            </div>
          </div>
          <div class="flex gap-2">
            <NButton variant="secondary" size="sm" @click="toggleActive(sub)">
              {{ sub.active ? 'Выключить' : 'Включить' }}
            </NButton>
            <NButton variant="secondary" size="sm" @click="showDeliveries(sub)">Доставки</NButton>
            <NButton variant="danger" size="sm" @click="remove(sub)">Удалить</NButton>
          </div>
        </div>

        <div v-if="deliveriesFor === sub.id" class="overflow-x-auto">
          <table v-if="deliveries.length" class="w-full text-left text-xs">
            <thead>
              <tr class="text-muted">
                <th class="py-1">Событие</th>
                <th class="py-1">Попытка</th>
                <th class="py-1">Код</th>
                <th class="py-1">Итог</th>
                <th class="py-1">Ошибка</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="d in deliveries" :key="d.id" class="border-t border-line">
                <td class="py-1">{{ d.event_name }}</td>
                <td class="py-1">{{ d.attempt }}</td>
                <td class="py-1">{{ d.status_code ?? '—' }}</td>
                <td class="py-1">{{ outcome(d) }}</td>
                <td class="py-1 break-all">{{ d.error_message ?? '—' }}</td>
              </tr>
            </tbody>
          </table>
          <p v-else class="text-xs text-muted">Доставок пока не было.</p>
        </div>
      </div>
    </section>
  </div>
</template>
