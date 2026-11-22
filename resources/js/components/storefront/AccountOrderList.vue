<script setup lang="ts">
import NStatusBadge from '@/components/ui/NStatusBadge.vue'
import type { CustomerOrder } from '@/lib/customer'
import { dateTime, money } from '@/lib/format'
defineProps<{ orders: CustomerOrder[] }>()
</script>

<template>
  <ul class="account-orders">
    <li v-for="order in orders" :key="order.id">
      <details>
        <summary>
          <span class="order-symbol" aria-hidden="true">▤</span>
          <span class="order-name"><strong>{{ order.eventTitles[0] || 'Заказ ' + order.number }}</strong><small>{{ order.number }} · {{ order.createdAt && !Number.isNaN(Date.parse(order.createdAt)) ? dateTime(order.createdAt) : 'Дата уточняется' }}</small></span>
          <span class="order-payment"><strong>{{ money(order.totalMinor, { currency: order.currency }) }}</strong><NStatusBadge kind="order" :status="order.status" /></span>
          <span class="order-chevron" aria-hidden="true">⌄</span>
        </summary>
        <div class="order-details">
          <p class="text-sm text-muted">Состав заказа</p>
          <ul><li v-for="(title, index) in order.eventTitles" :key="index">{{ title }}</li></ul>
          <p v-if="order.email" class="text-sm text-muted">Почта для билетов: {{ order.email }}</p>
          <RouterLink v-if="['paid', 'partially_refunded'].includes(order.status)" :to="{ path: '/tickets', query: { order: order.id } }">Билеты этого заказа</RouterLink>
          <p v-else class="text-sm text-muted">Билеты доступны после успешной оплаты заказа.</p>
        </div>
      </details>
    </li>
  </ul>
</template>

<style scoped>
.account-orders>li+li{border-top:1px solid rgb(var(--line))}summary{display:flex;align-items:center;gap:14px;cursor:pointer;list-style:none;padding:20px 0}summary::-webkit-details-marker{display:none}summary:focus-visible{outline:2px solid #9572d7;outline-offset:3px}.order-symbol{width:42px;height:48px;display:grid;place-items:center;border-radius:10px;background:rgb(var(--surface-3));color:rgb(var(--content-muted));font-size:22px;flex-shrink:0}.order-name{flex:1;min-width:0}.order-name strong{font-size:15px;font-weight:650;display:block;overflow-wrap:anywhere}.order-name small{display:block;font-size:12px;color:rgb(var(--content-muted));margin-top:5px;line-height:1.6}.order-payment{display:flex;align-items:flex-end;flex-direction:column;gap:6px;flex-shrink:0}.order-payment strong{font-size:16px}.order-chevron{color:rgb(var(--content-muted));transition:transform .2s}details[open] .order-chevron{transform:rotate(180deg)}.order-details{padding:0 0 22px 56px;display:grid;gap:12px;overflow-wrap:anywhere}.order-details>ul{font-size:14px;display:grid;gap:6px}.order-details>a{color:rgb(var(--content));font-weight:600;font-size:14px;text-decoration:underline;text-underline-offset:4px}
@media(max-width:600px){summary{gap:9px;flex-wrap:wrap}.order-symbol{display:none}.order-name{flex-basis:55%}.order-payment strong{font-size:14px}.order-name strong{font-size:14px}.order-chevron{display:none}.order-details{padding-left:0}}
</style>
