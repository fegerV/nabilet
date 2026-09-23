<script setup lang="ts" generic="T extends { id: string }">
/**
 * Таблица для админки.
 *
 * Администратор работает с таблицей каждый день, поэтому здесь важны три вещи:
 * колонки не «прыгают» при сортировке, строку можно целиком открыть кликом,
 * а на мобильном таблица превращается в список карточек, а не в горизонтальный
 * скролл, в котором невозможно читать.
 */
import { computed, ref } from 'vue'
import { cn } from '@/lib/cn'

export interface Column {
  key: string
  label: string
  align?: 'left' | 'right' | 'center'
  sortable?: boolean
  width?: string
  /** Скрыть на узких экранах — второстепенные колонки. */
  hideOnMobile?: boolean
}

const props = withDefaults(
  defineProps<{
    columns: Column[]
    rows: T[]
    rowKey?: string
    sort?: string
    sortDir?: 'asc' | 'desc'
    loading?: boolean
    emptyTitle?: string
    emptyDescription?: string
  }>(),
  { rowKey: 'id', sortDir: 'desc' },
)

const emit = defineEmits<{
  'update:sort': [key: string]
  'update:sortDir': [dir: 'asc' | 'desc']
  row: [row: T]
}>()

const internalSort = ref(props.sort ?? '')
const internalDir = ref<'asc' | 'desc'>(props.sortDir)

const activeSort = computed(() => props.sort ?? internalSort.value)
const activeDir = computed(() => props.sortDir ?? internalDir.value)

function toggleSort(column: Column): void {
  if (!column.sortable) return
  if (activeSort.value === column.key) {
    const next = activeDir.value === 'asc' ? 'desc' : 'asc'
    internalDir.value = next
    emit('update:sortDir', next)
  } else {
    internalSort.value = column.key
    emit('update:sort', column.key)
  }
}
</script>

<template>
  <div class="overflow-hidden rounded-xl border border-line bg-surface">
    <!-- Десктопная таблица -->
    <table class="hidden w-full border-collapse md:table">
      <thead>
        <tr class="border-b border-line bg-surface-2">
          <th
            v-for="column in columns"
            :key="column.key"
            scope="col"
            :style="column.width ? { width: column.width } : undefined"
            :class="
              cn(
                'px-4 py-3 text-xs font-medium uppercase tracking-wide text-subtle',
                column.align === 'right' ? 'text-right' : column.align === 'center' ? 'text-center' : 'text-left',
                column.sortable && 'cursor-pointer select-none hover:text-muted',
              )
            "
            :aria-sort="activeSort === column.key ? (activeDir === 'asc' ? 'ascending' : 'descending') : 'none'"
            @click="toggleSort(column)"
          >
            {{ column.label }}
            <span v-if="column.sortable && activeSort === column.key" class="ml-1 text-brand-400">
              {{ activeDir === 'asc' ? '↑' : '↓' }}
            </span>
          </th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="row in rows"
          :key="String(row[rowKey as keyof T])"
          class="cursor-pointer border-b border-line/60 transition-colors last:border-0 hover:bg-surface-2"
          @click="emit('row', row)"
        >
          <td
            v-for="column in columns"
            :key="column.key"
            :class="
              cn(
                'px-4 py-3 text-sm text-content',
                column.align === 'right' ? 'text-right' : column.align === 'center' ? 'text-center' : 'text-left',
              )
            "
          >
            <slot :name="`cell-${column.key}`" :row="row">{{ row[column.key as keyof T] }}</slot>
          </td>
        </tr>
      </tbody>
    </table>

    <!-- Мобильный список: та же информация, но вертикально -->
    <ul class="divide-y divide-line md:hidden">
      <li v-for="row in rows" :key="String(row[rowKey as keyof T])" class="px-4 py-3 active:bg-surface-2" @click="emit('row', row)">
        <div class="flex items-baseline justify-between gap-3">
          <div class="min-w-0 flex-1">
            <slot name="mobile-title" :row="row" />
          </div>
          <div class="flex-none text-right">
            <slot :name="`cell-${columns[columns.length - 1].key}`" :row="row" />
          </div>
        </div>
        <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-subtle">
          <slot name="mobile-meta" :row="row" />
        </div>
      </li>
    </ul>

    <div v-if="rows.length === 0 && !loading" class="px-4 py-14 text-center">
      <p class="text-sm font-medium text-content">{{ emptyTitle ?? 'Ничего не найдено' }}</p>
      <p v-if="emptyDescription" class="mt-1 text-xs text-subtle">{{ emptyDescription }}</p>
    </div>

    <div v-if="loading" class="space-y-2 p-4">
      <div v-for="n in 5" :key="n" class="skeleton h-10 w-full" />
    </div>
  </div>
</template>
