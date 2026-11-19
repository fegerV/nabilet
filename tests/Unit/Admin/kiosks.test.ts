/**
 * Страница парка киосков: проверяем, что аналитика и управления продажами
 * не «потерялись» при верстке (раньше страницы для устройств вообще не было —
 * эндпоинты были, а UI не достигал их).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import AdminKiosksPage from '@/pages/admin/AdminKiosksPage.vue'

const { getMock, sendMock } = vi.hoisted(() => ({
  getMock: vi.fn(),
  sendMock: vi.fn(),
}))

vi.mock('@/lib/api', () => ({ get: getMock, send: sendMock }))

const device = {
  id: 1,
  public_id: '01KioskTestPublicId000000000001',
  name: 'Киоск у входа',
  location_address: 'ул. Центральная, 1',
  location_note: null,
  status: 'active',
  sales_enabled: false,
  platform: 'web_kiosk',
  app_version: null,
  config_version: null,
  target_version: null,
  update_status: 'none',
  update_applied_version: null,
  update_error: null,
  last_seen_at: new Date().toISOString(),
  last_used_at: null,
  revoked_at: null,
  kiosk_token_hash: 'a'.repeat(64),
  cart_token: '00000000-0000-4000-8000-000000000001',
  created_at: new Date().toISOString(),
}

const summary = {
  devices: { total: 1, active: 1, disabled: 0, revoked: 0, selling: 0, online: 1 },
  today: { sales: 3, revenue_minor: 1_500_000 },
  week: { sales: 10, revenue_minor: 5_000_000 },
  top: [{ device_id: 1, device_name: 'Киоск у входа', sales_count: 10, revenue_minor: 5_000_000 }],
  offline_after_minutes: 5,
}

describe('AdminKiosksPage', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    getMock.mockReset()
    sendMock.mockReset()
    getMock.mockImplementation(async (path: string) => {
      if (path.startsWith('/admin/kiosks')) {
        return { data: { data: [device] }, summary }
      }
      if (path.startsWith('/organizations')) {
        return { data: { data: [{ id: 1, name: 'Тестовая организация' }] } }
      }
      return { data: { data: [] } }
    })
    sendMock.mockResolvedValue({ data: {} })
  })

  it('показывает аналитику и реестр после загрузки', async () => {
    const wrapper = mount(AdminKiosksPage)
    await flushPromises()

    expect(wrapper.text()).toContain('Информационные киоски')
    expect(wrapper.text()).toContain('Киоск у входа')
    expect(wrapper.text()).toContain('Продают сейчас')
    expect(wrapper.text()).toContain('Топ киосков за 7 дней')
    expect(getMock).toHaveBeenCalledWith('/admin/kiosks?per_page=100')
  })

  it('переключает продажи через PATCH и обновляет список', async () => {
    const wrapper = mount(AdminKiosksPage)
    await flushPromises()

    // Кнопка «Выключены» в строке: её нажатие вызывает PATCH sales_enabled=true.
    const buttons = wrapper.findAll('button')
    const toggle = buttons.find((b) => b.text() === 'Выключены')
    expect(toggle).toBeDefined()
    await toggle!.trigger('click')
    await flushPromises()

    expect(sendMock).toHaveBeenCalledWith('/admin/kiosks/01KioskTestPublicId000000000001', 'PATCH', {
      sales_enabled: true,
    })
  })
})
