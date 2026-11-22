import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import TicketCanvas from '@/components/storefront/TicketCanvas.vue'

vi.mock('qrcode', () => ({
  default: { toDataURL: vi.fn(async () => 'data:image/png;base64,QR') },
}))
vi.mock('jsbarcode', () => ({
  default: vi.fn((svg: SVGElement) => svg.setAttribute('data-barcode', 'ok')),
}))

/** Витрина должна показывать тот же дизайн, что администратор видел в редакторе. */
describe('TicketCanvas', () => {
  beforeEach(() => {
    vi.stubGlobal('ResizeObserver', class {
      observe() {}
      disconnect() {}
    })
  })

  const ticket = {
    eventTitle: 'Metallica в Вавилоне',
    venue: 'Вавилон, Сургут',
    sessionAt: '2026-12-19T20:00:00+03:00',
    sector: 'Партер',
    row: 5,
    seat: 12,
    code: '01JTESTCODE',
    holder: 'Иван',
    qrPayload: 'NB1.test',
    status: 'issued',
    priceMinor: 155050,
    orderId: 42,
  }

  function mountCanvas() {
    return mount(TicketCanvas, {
      props: {
        ticket,
        config: {
          width: 400,
          height: 600,
          backgroundColor: '#101010',
          backgroundGradient: { from: '#101010', direction: 'to bottom' },
          elements: [
            { id: 1, type: 'text', x: 10, y: 10, width: 300, content: '{{event.name}}', rotation: 15, opacity: 0.5 },
            { id: 2, type: 'image', x: 10, y: 50, width: 100, height: 80, src: '/storage/poster.jpg' },
            { id: 3, type: 'barcode', x: 10, y: 150, width: 200, height: 50 },
            { id: 4, type: 'qr', x: 10, y: 250, size: 120 },
            { id: 5, type: 'rectangle', x: 0, y: 0, width: 400, height: 20, rotation: 8, opacity: 0.8 },
            { id: 6, type: 'circle', cx: 50, cy: 50, r: 20, opacity: 0.6 },
          ],
        },
      },
    })
  }

  it('substitutes variables and renders real media instead of placeholders', async () => {
    const wrapper = mountCanvas()
    await Promise.resolve()
    await Promise.resolve()

    expect(wrapper.text()).toContain('Metallica в Вавилоне')
    expect(wrapper.text()).not.toContain('{{event.name}}')
    expect(wrapper.find('img[alt="Оформление билета"]').exists()).toBe(true)
    expect(wrapper.find('img[alt="Штрихкод билета"]').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('|| || ||')
    expect(wrapper.text()).not.toContain('IMG')
  })

  it('applies rotation and opacity from the builder to every element type', () => {
    const wrapper = mountCanvas()
    const rotated = wrapper.findAll('[style*="rotate(15deg)"]')
    expect(rotated.length).toBeGreaterThan(0)
    expect(wrapper.html()).toContain('opacity: 0.5')
    expect(wrapper.html()).toContain('opacity: 0.8')
    expect(wrapper.html()).toContain('opacity: 0.6')
  })

  it('keeps a gradient with only a from color, like the builder preview', () => {
    const wrapper = mountCanvas()
    expect(wrapper.find('.ticket-canvas').attributes('style')).toContain('linear-gradient')
  })

  it('recalculates the mobile scale when the ticket template dimensions change', async () => {
    const wrapper = mountCanvas()
    const root = wrapper.find('.ticket-canvas-root').element
    Object.defineProperty(root, 'clientWidth', { configurable: true, value: 200 })

    await wrapper.setProps({
      config: {
        width: 800,
        height: 400,
        backgroundColor: '#fff',
        elements: [],
      },
    })

    // 200px available / 800px canvas = 0.25 scale; outer width stays 200px.
    expect(wrapper.find('.ticket-canvas-root > div').attributes('style')).toContain('width: 200px')
  })

  it('shows the same fallback QR as the PDF when a layout has no QR element', async () => {
    const wrapper = mountCanvas()
    await wrapper.setProps({
      config: { width: 400, height: 600, backgroundColor: '#fff', elements: [] },
    })
    await Promise.resolve()

    const qr = wrapper.find('img[alt="QR билета"]')
    expect(qr.exists()).toBe(true)
    expect(qr.element.parentElement?.getAttribute('style')).toContain('left: 130px')
    expect(qr.element.parentElement?.getAttribute('style')).toContain('top: 420px')
  })
})
