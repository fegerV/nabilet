import { createApp, h } from '/node_modules/vue/dist/vue.esm-bundler.js'
import CoordSeatMap from '/resources/js/components/seat/CoordSeatMap.vue'
import '/resources/css/app.css'

const response = await fetch('/outputs/hall-babylon-schema-restored.json')
if (!response.ok) throw new Error('Не удалось загрузить опубликованную схему Вавилона')
const schema = await response.json()
const canvas = schema.canvas ?? { width: 900, height: 520 }
const inventory = []
let id = 0

for (const sector of schema.sectors ?? []) {
  for (const seat of sector.seats ?? []) {
    id += 1
    inventory.push({
      id,
      session_id: 1,
      type: 'seat',
      price_amount: sector.rowPrices?.[seat.row] ?? sector.priceMinor ?? 0,
      available_quantity: 1,
      status: 'available',
      seat: {
        id,
        row_id: seat.row,
        row_number: seat.row,
        sector_name: sector.name,
        number: seat.number,
        type: seat.kind,
        x: Math.round(((Number(seat.x ?? 0) + Number(sector.x ?? 0)) / canvas.width) * 60),
        y: Math.round(((Number(seat.y ?? 0) + Number(sector.y ?? 0)) / canvas.height) * 40),
      },
    })
  }
}

const standing = (schema.staticObjects ?? []).find((object) => object.kind === 'standing')
if (standing) {
  inventory.push({
    id: id + 1,
    session_id: 1,
    type: 'standing',
    price_amount: standing.priceMinor ?? 70000,
    capacity: standing.capacity ?? 95,
    available_quantity: standing.capacity ?? 95,
    status: 'available',
    seat: null,
  })
}

window.__previewInventory = inventory
window.__previewSchema = schema

window.__previewToggles = []

createApp({
  render: () => h(CoordSeatMap, {
    inventory,
    selected: [],
    selectedCount: 0,
    maxQuantity: 10,
    onToggle: (item, quantity) => window.__previewToggles.push({ id: item.id, quantity }),
  }),
}).mount('#app')
