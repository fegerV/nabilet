<script setup lang="ts">
/**
 * «Клуб зрителей» — настройки лояльности: ранний доступ (фаза 3b) и
 * реферальная программа (фаза 4).
 *
 * ОДИН ЭКРАН, ДВЕ НЕЗАВИСИМЫЕ ГРУППЫ
 *   Ранний доступ и рефералка правятся из одной формы админки, но сохраняются
 *   порознь — ровно как это делает сервер (`LoyaltySettingsController`: группы
 *   полей проверяются отдельно, и правка награды не затирает порог раннего
 *   доступа). Поэтому у каждой карточки своя кнопка и своя «грязность».
 *
 * РУБЛИ НА ЭКРАНЕ, МИНОРНЫЕ ЕДИЦИЫ В API
 *   Награды приходят и уходят в минорных единицах (`referral_*_bonus`), как и
 *   все `*_amount` в API. Форма показывает рубли: «300 ₽», а не «30000».
 *   Перевод делает только этот экран — сервер остаётся единственным, кто
 *   работает в копейках.
 *
 * НАГРАДА — ЭТО ДЕНЬГИ, А НЕ КОСМЕТИКА
 *   Порог раннего доступа открывает продажу раньше общего старта, а размер
 *   награды — это бонусы на счетах покупателей. Поэтому раздел доступен только
 *   сотруднику (`admin` поверх `auth:api`), как и настройки платежей.
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
import { minorToRub, rubToMinor } from '@/lib/money'

interface ClubLevel {
  slug: string
  name: string
  min_paid_orders: number
}

interface ClubSettingsData {
  // Ранний доступ (фаза 3b)
  enabled: boolean
  hours: number
  min_level_slug: string
  // Реферальная программа (фаза 4)
  referral_enabled: boolean
  referral_inviter_bonus: number
  referral_invited_bonus: number
  levels: ClubLevel[]
}

/** Потолок награды — тот же, что у `ReferralPolicy::MAX_BONUS` (в рублях). */
const MAX_REWARD_RUB = 10000

const ui = useUiStore()

const loading = ref(true)
const error = ref<string | null>(null)

const levels = ref<ClubLevel[]>([])

/* ── Форма раннего доступа ────────────────────────────────────────────────── */
const accessForm = ref({ enabled: false, hours: 24, min_level_slug: 'friend' })
const accessSaved = ref('')
const savingAccess = ref(false)

/* ── Форма реферальной программы ──────────────────────────────────────────── */
const referralForm = ref({ enabled: true, inviter_rub: 300, invited_rub: 300 })
const referralSaved = ref('')
const savingReferral = ref(false)

const accessDirty = computed(() => JSON.stringify(accessForm.value) !== accessSaved.value)
const referralDirty = computed(() => JSON.stringify(referralForm.value) !== referralSaved.value)

const levelOptions = computed(() =>
  levels.value.map((l) => ({ value: l.slug, label: `${l.name} (от ${l.min_paid_orders})` })),
)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const res = await get<{ data: ClubSettingsData }>('/admin/loyalty/settings')
    const payload = res.data as unknown as { data?: ClubSettingsData }
    const data = payload.data ?? (res.data as unknown as ClubSettingsData)

    levels.value = data?.levels ?? []
    accessForm.value = {
      enabled: Boolean(data?.enabled),
      hours: Number(data?.hours ?? 24),
      min_level_slug: String(data?.min_level_slug ?? 'friend'),
    }
    referralForm.value = {
      enabled: Boolean(data?.referral_enabled),
      inviter_rub: minorToRub(Number(data?.referral_inviter_bonus ?? 30000)),
      invited_rub: minorToRub(Number(data?.referral_invited_bonus ?? 30000)),
    }
    accessSaved.value = JSON.stringify(accessForm.value)
    referralSaved.value = JSON.stringify(referralForm.value)
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    loading.value = false
  }
}

onMounted(load)

async function saveAccess(): Promise<void> {
  savingAccess.value = true
  try {
    await send('/admin/loyalty/settings', 'PUT', { ...accessForm.value })
    accessSaved.value = JSON.stringify(accessForm.value)
    ui.notify('mint', 'Ранний доступ сохранён', 'Продажи откроются по новому правилу сразу.')
  } catch (e) {
    ui.notify('rose', 'Не удалось сохранить', e instanceof Error ? e.message : String(e))
  } finally {
    savingAccess.value = false
  }
}

async function saveReferral(): Promise<void> {
  savingReferral.value = true
  try {
    const body = {
      referral_enabled: referralForm.value.enabled,
      // Ноль здесь — законная награда (`ReferralPolicy::normalize` держит
      // диапазон `[0, MAX_BONUS]`), поэтому `allowZero`, а не отказ.
      referral_inviter_bonus: rubToMinor(referralForm.value.inviter_rub, { allowZero: true }) ?? 0,
      referral_invited_bonus: rubToMinor(referralForm.value.invited_rub, { allowZero: true }) ?? 0,
    }
    await send('/admin/loyalty/settings', 'PUT', body)
    referralSaved.value = JSON.stringify(referralForm.value)
    ui.notify('mint', 'Настройки реферальной программы сохранены', 'Новые приглашения будут считать награду по ним.')
  } catch (e) {
    ui.notify('rose', 'Не удалось сохранить', e instanceof Error ? e.message : String(e))
  } finally {
    savingReferral.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-[900px]">
    <NPageHeader
      title="Клуб зрителей"
      subtitle="Ранний доступ к продажам и реферальная программа. Обе настройки двигают деньги: первая открывает продажу раньше общего старта, вторая начисляет бонусы за приведённого друга."
    />

    <div v-if="error" class="surface-card mt-5 border-rose-500/30 px-4 py-3 text-sm text-rose-400">
      Не удалось загрузить настройки: {{ error }}
    </div>

    <template v-if="!loading && !error">
      <!-- Ранний доступ -->
      <NCard
        class="mt-5"
        title="Ранний доступ к продажам"
        description="Покупатели клуба указанного уровня и выше входят в продажу на N часов раньше общего старта. Статус сеанса и дата закрытия продаж при этом не меняются — сдвигается только начало окна."
      >
        <div class="grid gap-4 sm:grid-cols-2">
          <NInput
            v-model.number="accessForm.hours"
            type="number"
            label="Начинается раньше на, часов"
            :min="1"
            :max="720"
            hint="От 1 до 720 часов (30 суток)"
          />
          <div>
            <span class="mb-1.5 block text-sm font-medium text-content">Порог уровня клуба</span>
            <NSelect v-model="accessForm.min_level_slug" :options="levelOptions" />
          </div>
        </div>

        <div class="mt-4">
          <NCheckbox
            v-model="accessForm.enabled"
            label="Включить ранний доступ"
            description="Без включения продажи открываются по общему расписанию для всех."
          />
        </div>

        <div class="mt-5 flex items-center gap-3">
          <NButton :disabled="!accessDirty || savingAccess" @click="saveAccess">
            {{ savingAccess ? 'Сохранение…' : 'Сохранить' }}
          </NButton>
          <span v-if="accessDirty" class="text-xs text-sun-400">Есть несохранённые изменения</span>
        </div>
      </NCard>

      <!-- Реферальная программа -->
      <NCard
        class="mt-5"
        title="Реферальная программа"
        description="Покупатель делится ссылкой из кабинета. Когда друг оплачивает первый заказ, награда приходит обеим сторонам — один раз на каждого друга."
      >
        <template #actions>
          <span
            class="rounded-full px-2.5 py-1 text-xs font-medium"
            :class="referralForm.enabled ? 'bg-mint-500/15 text-mint-400' : 'bg-surface-3 text-subtle'"
          >
            {{ referralForm.enabled ? 'программа включена' : 'программа выключена' }}
          </span>
        </template>

        <div class="grid gap-4 sm:grid-cols-2">
          <NInput
            v-model.number="referralForm.inviter_rub"
            type="number"
            label="Награда приглашающему, ₽"
            :min="0"
            :max="MAX_REWARD_RUB"
            hint="Начисляется бонусами на счёт"
          />
          <NInput
            v-model.number="referralForm.invited_rub"
            type="number"
            label="Награда другу, ₽"
            :min="0"
            :max="MAX_REWARD_RUB"
            hint="За первый оплаченный заказ"
          />
        </div>

        <div class="mt-4">
          <NCheckbox
            v-model="referralForm.enabled"
            label="Включить программу"
            description="Выключенная программа не начисляет награды по новым приглашениям. Ссылки из кабинета продолжают работать как обычные ссылки."
          />
        </div>

        <div class="mt-5 flex items-center gap-3">
          <NButton :disabled="!referralDirty || savingReferral" @click="saveReferral">
            {{ savingReferral ? 'Сохранение…' : 'Сохранить' }}
          </NButton>
          <span v-if="referralDirty" class="text-xs text-sun-400">Есть несохранённые изменения</span>
        </div>
      </NCard>

      <p class="mt-4 text-xs text-white/40">
        Награды начисляются бонусами на счёт по тем же правилам, что и кэшбэк: списание — в чекауте,
        возврат не отзывает награду (см. «Бонусный счёт» в документации лояльности).
      </p>
    </template>
  </div>
</template>
