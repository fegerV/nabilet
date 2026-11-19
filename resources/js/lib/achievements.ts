/** Достижения без денежных наград: только подтверждённый сервером счётчик. */
export function purchaseAchievements(paidOrders: number) {
  const count = Number.isFinite(paidOrders) ? Math.max(0, Math.floor(paidOrders)) : 0
  return [
    { id: 'first', title: 'Первый выход', description: 'Первая оплаченная покупка', target: 1, icon: '✦' },
    { id: 'regular', title: 'В ритме города', description: 'Пять оплаченных заказов', target: 5, icon: '♫' },
    { id: 'patron', title: 'Завсегдатай', description: 'Десять оплаченных заказов', target: 10, icon: '★' },
  ].map(item => ({ ...item, earned: count >= item.target, progress: Math.min(count, item.target) }))
}
