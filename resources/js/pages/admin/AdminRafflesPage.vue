<script setup lang="ts">
/**
 * Розыгрыши «счастливый билет» — экран организатора (фаза 5).
 *
 * ОДИН ЭКРАН НА ВЕСЬ ЖИЗНЕННЫЙ ЦИКЛ
 *   Черновик → набор участников → тираж. Экран показывает список розыгрышей,
 *   форму создания/правки (поля + призы) и результаты тиража. Отдельных
 *   страниц нарочно нет: организатор работает с одним розыгрышем за раз, и
 *   переключаться между списком и формой хуже, чем видеть оба на одном экране.
 *
 * ТИРАЖ — ПОДТВЕРЖДАЕМОЕ ДЕЙСТВИЕ
 *   Распределение призов необратимо (`drawn` терминален), поэтому кнопка
 *   «Провести тираж» спрашивает подтверждение и показывает состав: сколько
 *   билетов и сколько призовых мест. Отказы сервера (`RAFFLE_NOT_ENOUGH_
 *   ENTRIES` и другие) показываются текстом — они не ошибки интерфейса, а
 *   правила розыгрыша.
 *
 * ДАТЫ ОКНА
 *   `starts_at`/`ends_at` необязательны: пустое начало — «с начала времён»,
 *   пустой конец — «до тиража». Перевод ISO ↔ `datetime-local` живёт в
 *   `lib/raffles.ts`, чтобы форма и показ не разошлись.
 */
import { computed, onMounted, ref } from 'vue'
import NButton from '@/components/ui/NButton.vue'
import NBadge from '@/components/ui/NBadge.vue'
import NCard from '@/components/ui/NCard.vue'
import NInput from '@/components/ui/NInput.vue'
import NModal from '@/components/ui/NModal.vue'
import NPageHeader from '@/components/ui/NPageHeader.vue'
import NStatusBadge from '@/components/ui/NStatusBadge.vue'
import { useUiStore } from '@/stores/ui'
import {
  activateAdminRaffle,
  cancelAdminRaffle,
  createAdminRaffle,
  drawAdminRaffle,
  fetchAdminRaffles,
  fetchAdminRaffle,
  fromLocalInput,
  notifyAdminRaffle,
  toLocalInput,
  updateAdminRaffle,
  type RaffleDetail,
  type RaffleListItem,
  type RafflePrizeDraft,
} from '@/lib/raffles'

const ui = useUiStore()

interface PrizeRow extends RafflePrizeDraft {
  key: number
}

const loading = ref(true)
const error = ref<string | null>(null)
const raffles = ref<RaffleListItem[]>([])

const selected = ref<RaffleDetail | null>(null)
const formOpen = ref(false)
const editing = ref(false)
const saving = ref(false)
const actionBusy = ref<string | null>(null)

const drawConfirm = ref(false)
const cancelConfirm = ref(false)

/** Черновик формы: пустой розыгрыш с одним призовым местом. */
function emptyForm(): { title: string; description: string; starts: string; ends: string; prizes: PrizeRow[] } {
  return {
    title: '',
    description: '',
    starts: '',
    ends: '',
    prizes: [{ key: 1, place: 1, title: '', description: '' }],
  }
}

const form = ref(emptyForm())

const pendingNotifications = computed(
  () => selected.value?.winners.filter((winner) => !winner.notified).length ?? 0,
)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    raffles.value = await fetchAdminRaffles()
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    loading.value = false
  }
}

onMounted(load)

function openCreate(): void {
  editing.value = false
  selected.value = null
  form.value = emptyForm()
  formOpen.value = true
}

async function openEdit(publicId: string): Promise<void> {
  actionBusy.value = publicId
  try {
    const detail = await fetchAdminRaffle(publicId)
    selected.value = detail
    editing.value = true
    form.value = {
      title: detail.title,
      description: detail.description ?? '',
      starts: toLocalInput(detail.starts_at),
      ends: toLocalInput(detail.ends_at),
      prizes: (detail.prizes ?? []).map((prize, index) => ({
        key: index + 1,
        place: prize.place,
        title: prize.title,
        description: prize.description ?? '',
      })),
    }
    formOpen.value = true
  } catch (e) {
    ui.notify('rose', 'Не удалось открыть розыгрыш', e instanceof Error ? e.message : String(e))
  } finally {
    actionBusy.value = null
  }
}

/**
 * Открыть розыгрыш из списка и сразу предложить тираж.
 *
 * Кнопка списка не может тиражировать «в лоб»: подтверждение показывает
 * название, число билетов и призов, а список несёт только счётчики.
 * Поэтому сначала догружаем деталь — и уже потом открываем подтверждение.
 */
async function prepareDraw(publicId: string): Promise<void> {
  actionBusy.value = `open:${publicId}`
  try {
    selected.value = await fetchAdminRaffle(publicId)
    drawConfirm.value = true
  } catch (e) {
    ui.notify('rose', 'Не удалось открыть розыгрыш', e instanceof Error ? e.message : String(e))
  } finally {
    actionBusy.value = null
  }
}

function addPrize(): void {
  const next = form.value.prizes.reduce((max, row) => Math.max(max, row.place), 0) + 1
  form.value.prizes.push({ key: Date.now(), place: next, title: '', description: '' })
}

function removePrize(index: number): void {
  form.value.prizes.splice(index, 1)
  if (form.value.prizes.length === 0) {
    // Пустой список призов сохранению не подлежит: сервер требует хотя бы
    // одно место, и молчаливое сохранение «розыгрыша без призов» — ловушка.
    addPrize()
  }
}

async function saveForm(): Promise<void> {
  saving.value = true
  try {
    const payload = {
      title: form.value.title,
      description: form.value.description,
      starts_at: fromLocalInput(form.value.starts),
      ends_at: fromLocalInput(form.value.ends),
      prizes: form.value.prizes
        .filter((row) => row.title.trim() !== '')
        .map((row) => ({ place: row.place, title: row.title.trim(), description: row.description.trim() })),
    }

    const saved = editing.value && selected.value
      ? await updateAdminRaffle(selected.value.public_id, payload)
      : await createAdminRaffle(payload)

    selected.value = saved
    formOpen.value = false
    ui.notify('mint', editing.value ? 'Розыгрыш сохранён' : 'Черновик создан', editing.value ? 'Изменения применены.' : 'Активируйте розыгрыш, когда призы будут готовы.')
    await load()
  } catch (e) {
    ui.notify('rose', 'Не удалось сохранить', e instanceof Error ? e.message : String(e))
  } finally {
    saving.value = false
  }
}

async function runAction(kind: 'activate' | 'cancel' | 'draw' | 'notify', publicId: string): Promise<void> {
  actionBusy.value = `${kind}:${publicId}`
  try {
    if (kind === 'draw') {
      const winners = await drawAdminRaffle(publicId)
      drawConfirm.value = false
      ui.notify('mint', 'Тираж проведён', `Победителей: ${winners.length}. Письма победителям уйдут очередью — статус отправки виден у каждого победителя.`)
    } else if (kind === 'notify') {
      const result = await notifyAdminRaffle(publicId)
      ui.notify('mint', 'Письма в работе', result.pending > 0 ? `Отправляем ${result.pending} писем.` : 'Всем победителям письмо уже уходило.')
    } else {
      await (kind === 'activate' ? activateAdminRaffle(publicId) : cancelAdminRaffle(publicId))
      cancelConfirm.value = false
      ui.notify('mint', kind === 'activate' ? 'Розыгрыш активирован' : 'Розыгрыш отменён', kind === 'activate' ? 'Оплаченные заказы окна становятся билетами.' : 'Участники видят розыгрыш как отменённый.')
    }

    selected.value = await fetchAdminRaffle(publicId)
    await load()
  } catch (e) {
    // Отказы сервера — правила розыгрыша, а не сбой интерфейса: показываем
    // текст как есть (`RAFFLE_NOT_ENOUGH_ENTRIES` и другие).
    ui.notify('rose', 'Действие не выполнено', e instanceof Error ? e.message : String(e))
  } finally {
    actionBusy.value = null
  }
}

function formatDate(value: string | null): string {
  if (!value) return '—'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' })
}
</script>

<template>
  <div class="mx-auto max-w-[1100px]">
    <NPageHeader
      title="Розыгрыши"
      subtitle="«Счастливый билет»: каждый оплаченный заказ из окна розыгрыша становится билетом. Тираж проводит организатор — призы уходят победителям по местам."
    />

    <div class="mt-5 flex items-center justify-between gap-3">
      <p class="text-sm text-white/50">
        Розыгрышей: <span class="text-content">{{ raffles.length }}</span>
      </p>
      <NButton variant="primary" @click="openCreate">Создать розыгрыш</NButton>
    </div>

    <div v-if="error" class="surface-card mt-5 border-rose-500/30 px-4 py-3 text-sm text-rose-400">
      Не удалось загрузить розыгрыши: {{ error }}
    </div>

    <div v-else-if="loading" class="surface-card mt-5 px-4 py-8 text-center text-sm text-white/40">Загрузка…</div>

    <div v-else-if="raffles.length === 0" class="surface-card mt-5 px-4 py-10 text-center">
      <p class="text-sm text-white/50">Розыгрышей пока нет.</p>
      <p class="mt-1 text-xs text-white/35">Создайте первый: укажите призовые места и окно, в котором заказы становятся билетами.</p>
    </div>

    <!-- Список розыгрышей -->
    <div v-else class="mt-5 space-y-3">
      <NCard v-for="raffle in raffles" :key="raffle.public_id" class="!p-0">
        <div class="flex flex-wrap items-center gap-3 px-4 py-3">
          <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2">
              <p class="truncate font-medium text-content">{{ raffle.title }}</p>
              <NStatusBadge kind="raffle" :status="raffle.status" />
            </div>
            <p class="mt-0.5 text-xs text-white/40">
              Окно: {{ formatDate(raffle.starts_at) }} — {{ formatDate(raffle.ends_at) }}
              <template v-if="raffle.drawn_at"> · тираж {{ formatDate(raffle.drawn_at) }}</template>
            </p>
          </div>

          <div class="flex items-center gap-4 text-xs text-white/50">
            <span title="Призовых мест">🏆 {{ raffle.prizes_count }}</span>
            <span title="Билетов">🎟 {{ raffle.entries_count }}</span>
            <span title="Победителей">🎉 {{ raffle.winners_count }}</span>
          </div>

          <div class="flex items-center gap-2">
            <NButton size="sm" :disabled="actionBusy !== null" @click="openEdit(raffle.public_id)">Открыть</NButton>
            <NButton
              v-if="raffle.status === 'draft'"
              size="sm"
              variant="primary"
              :disabled="actionBusy !== null"
              @click="runAction('activate', raffle.public_id)"
            >
              Активировать
            </NButton>
            <NButton
              v-if="raffle.status === 'active'"
              size="sm"
              variant="primary"
              :disabled="actionBusy !== null"
              @click="prepareDraw(raffle.public_id)"
            >
              Провести тираж
            </NButton>
          </div>
        </div>
      </NCard>
    </div>

    <!-- Выбранный розыгрыш -->
    <NCard v-if="selected" class="mt-5" title="Розыгрыш" :description="selected.description ?? undefined">
      <template #actions>
        <NStatusBadge kind="raffle" :status="selected.status" />
      </template>

      <dl class="grid gap-3 sm:grid-cols-3">
        <div>
          <dt class="text-xs text-white/40">Призовых мест</dt>
          <dd class="text-content">{{ selected.prizes.length }}</dd>
        </div>
        <div>
          <dt class="text-xs text-white/40">Билетов</dt>
          <dd class="text-content">{{ selected.entries_count ?? '—' }}</dd>
        </div>
        <div>
          <dt class="text-xs text-white/40">Тираж</dt>
          <dd class="text-content">{{ selected.drawn_at ? formatDate(selected.drawn_at) : 'не проведён' }}</dd>
        </div>
      </dl>

      <!-- Призы -->
      <div class="mt-5">
        <p class="mb-2 text-sm font-medium text-content">Призовые места</p>
        <ul class="space-y-1.5">
          <li
            v-for="prize in selected.prizes"
            :key="prize.public_id"
            class="flex items-center gap-3 rounded-lg bg-surface-2 px-3 py-2 text-sm"
          >
            <!-- `indigo` — цвет дефолтной палитры Tailwind: рисуется, но
                 обходит токены проекта. Номер места — акцент, значит `brand`. -->
            <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-brand-500/20 text-xs font-semibold text-brand-300">
              {{ prize.place }}
            </span>
            <span class="min-w-0 flex-1 truncate text-content">{{ prize.title }}</span>
            <span v-if="prize.description" class="hidden shrink-0 text-xs text-white/40 sm:block">{{ prize.description }}</span>
          </li>
        </ul>
      </div>

      <!-- Результаты тиража -->
      <div v-if="selected.winners.length > 0" class="mt-5">
        <p class="mb-2 text-sm font-medium text-content">Победители</p>
        <ul class="space-y-1.5">
          <li
            v-for="winner in selected.winners"
            :key="winner.public_id"
            class="flex flex-wrap items-center gap-3 rounded-lg bg-surface-2 px-3 py-2 text-sm"
          >
            <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-mint-500/20 text-xs font-semibold text-mint-400">
              {{ winner.place }}
            </span>
            <span class="min-w-0 flex-1 truncate text-content">{{ winner.prize_title }}</span>
            <span class="text-xs text-white/40">заказ {{ winner.order_number }} · {{ winner.customer_email }}</span>
            <NBadge :tone="winner.notified ? 'mint' : 'sun'">
              {{ winner.notified ? 'письмо ушло' : 'письмо не ушло' }}
            </NBadge>
          </li>
        </ul>
      </div>

      <!-- Действия -->
      <div class="mt-5 flex flex-wrap items-center gap-2">
        <NButton size="sm" :disabled="actionBusy !== null" @click="openEdit(selected.public_id)">Править</NButton>

        <NButton
          v-if="selected.status === 'draft'"
          size="sm"
          variant="primary"
          :disabled="actionBusy !== null"
          @click="runAction('activate', selected.public_id)"
        >
          Активировать
        </NButton>

        <NButton
          v-if="selected.status === 'active'"
          size="sm"
          variant="primary"
          :disabled="actionBusy !== null"
          @click="drawConfirm = true"
        >
          Провести тираж
        </NButton>

        <NButton
          v-if="selected.status === 'drawn' && pendingNotifications > 0"
          size="sm"
          :disabled="actionBusy !== null"
          @click="runAction('notify', selected.public_id)"
        >
          Разослать письма ({{ pendingNotifications }})
        </NButton>

        <NButton
          v-if="!['drawn', 'cancelled'].includes(selected.status)"
          size="sm"
          variant="ghost"
          :disabled="actionBusy !== null"
          @click="cancelConfirm = true"
        >
          Отменить розыгрыш
        </NButton>
      </div>
    </NCard>

    <!-- Форма создания/правки -->
    <NModal :open="formOpen" :title="editing ? 'Править розыгрыш' : 'Новый розыгрыш'" size="lg" @update:open="formOpen = $event">
      <div class="space-y-4">
        <NInput v-model="form.title" label="Название" placeholder="Например: «Счастливый билет октября»" />
        <NInput v-model="form.description" label="Описание" placeholder="Что за приз и по каким правилам разыгрывается" />

        <div class="grid gap-4 sm:grid-cols-2">
          <NInput v-model="form.starts" type="datetime-local" label="Окно с" hint="Пусто — с начала времён" />
          <NInput v-model="form.ends" type="datetime-local" label="Окно по" hint="Пусто — до тиража" />
        </div>

        <div>
          <div class="mb-2 flex items-center justify-between">
            <p class="text-sm font-medium text-content">Призовые места</p>
            <NButton size="sm" variant="ghost" @click="addPrize">Добавить место</NButton>
          </div>

          <div class="space-y-2">
            <div v-for="(prize, index) in form.prizes" :key="prize.key" class="flex items-start gap-2">
              <NInput v-model.number="prize.place" type="number" class="w-20 shrink-0" :min="1" label="Место" />
              <NInput v-model="prize.title" class="flex-1" label="Приз" placeholder="Билет на любое мероприятие" />
              <NInput v-model="prize.description" class="flex-1" label="Условия" placeholder="Необязательно" />
              <NButton
                size="sm"
                variant="ghost"
                class="mt-1 shrink-0"
                :disabled="form.prizes.length === 1"
                @click="removePrize(index)"
              >
                ✕
              </NButton>
            </div>
          </div>
        </div>
      </div>

      <template #footer>
        <div class="flex items-center justify-end gap-2">
          <NButton size="sm" variant="ghost" @click="formOpen = false">Отмена</NButton>
          <NButton size="sm" variant="primary" :disabled="saving || !form.title.trim()" @click="saveForm">
            {{ saving ? 'Сохранение…' : editing ? 'Сохранить' : 'Создать' }}
          </NButton>
        </div>
      </template>
    </NModal>

    <!-- Подтверждение тиража -->
    <NModal
      :open="drawConfirm"
      title="Провести тираж?"
      description="Билеты розыгрыша распределяются по призовым местам случайным образом. Результат необратим: повторный тираж невозможен, победителям уйдут письма."
      destructive
      @update:open="drawConfirm = $event"
    >
      <div v-if="selected" class="space-y-1 text-sm text-white/60">
        <p>Розыгрыш: <span class="text-content">{{ selected.title }}</span></p>
        <p>Билетов: <span class="text-content">{{ selected.entries_count ?? '—' }}</span></p>
        <p>Призовых мест: <span class="text-content">{{ selected.prizes.length }}</span></p>
      </div>

      <template #footer>
        <div class="flex items-center justify-end gap-2">
          <NButton size="sm" variant="ghost" @click="drawConfirm = false">Отмена</NButton>
          <NButton
            size="sm"
            variant="primary"
            :disabled="actionBusy !== null || !selected"
            @click="selected && runAction('draw', selected.public_id)"
          >
            Провести тираж
          </NButton>
        </div>
      </template>
    </NModal>

    <!-- Подтверждение отмены -->
    <NModal
      :open="cancelConfirm"
      title="Отменить розыгрыш?"
      description="Розыгрыш закроется без тиража: победителей не будет, участия перестанут отображаться в кабинетах. Отмена необратима."
      destructive
      @update:open="cancelConfirm = $event"
    >
      <template #footer>
        <div class="flex items-center justify-end gap-2">
          <NButton size="sm" variant="ghost" @click="cancelConfirm = false">Назад</NButton>
          <NButton
            size="sm"
            variant="danger"
            :disabled="actionBusy !== null || !selected"
            @click="selected && runAction('cancel', selected.public_id)"
          >
            Отменить розыгрыш
          </NButton>
        </div>
      </template>
    </NModal>
  </div>
</template>
