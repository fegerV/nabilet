<script setup lang="ts">
/**
 * Заглушка раздела админки.
 *
 * Появляется честно: раздел ещё не сделан, но он уже в навигации, и экран
 * говорит об этом прямо — вместо пустой страницы, которая выглядит как баг.
 */
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import NButton from '@/components/ui/NButton.vue'

const route = useRoute()

const TITLES: Record<string, string> = {
  tickets: 'Билеты',
  payments: 'Платежи',
  sessions: 'Сеансы',
  venues: 'Площадки',
  users: 'Пользователи',
  integrations: 'Интеграции',
  analytics: 'Аналитика',
}

const title = computed(() => TITLES[String(route.params.section ?? '')] ?? 'Раздел')
</script>

<template>
  <div class="mx-auto max-w-2xl">
    <h1 class="text-2xl font-bold tracking-tight text-content">{{ title }}</h1>
    <div class="surface-card mt-5 p-6">
      <p class="text-sm text-muted">
        Раздел спроектирован в навигации, но ещё не реализован: он появится в следующем
        срезе разработки. Паттерны для него уже заданы дизайн-системой — таблицы,
        статусы и формы берутся из тех же компонентов, что «Заказы» и «Мероприятия».
      </p>
      <div class="mt-4 flex gap-2">
        <NButton variant="secondary" @click="$router.push('/admin/orders')">К заказам</NButton>
        <NButton variant="secondary" @click="$router.push('/admin/events')">К мероприятиям</NButton>
      </div>
    </div>
  </div>
</template>
