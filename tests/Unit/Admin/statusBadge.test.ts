/**
 * Бейдж статуса обязан обновляться, когда статус меняется на живом экране.
 *
 * ЧТО ЗДЕСЬ ОХРАНЯЕТСЯ. `NStatusBadge` считал подпись и цвет обычными
 * константами в `setup()`:
 *
 *     const resolved = props.kind === 'order' ? ORDER[props.status] : …
 *     const meta = resolved ?? { … }
 *
 * Компонент показывал статус на момент СОЗДАНИЯ и больше никогда его не
 * пересчитывал. В таблицах это не было заметно — строка монтируется уже со
 * своим статусом, — а на форме мероприятия стало видно сразу: после нажатия
 * «Готова к продаже» событие переходило в `published`, дата публикации
 * подставлялась, а бейдж продолжал писать «Черновик». Экран утверждал, что
 * публикация не прошла, ровно в тот момент, когда она прошла.
 *
 * ПОЧЕМУ ПРОВЕРКА ИМЕННО НА СМЕНЕ ПРОПА. Тест, который монтирует бейдж с
 * одним статусом и сверяет подпись, проходил и на сломанной версии: он ничего
 * не говорит о реактивности. Различие между «работает» и «показывает первое
 * значение навсегда» видно только при изменении пропа — то есть проверка
 * должна сама изменить входные данные и убедиться, что выход изменился.
 */
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import NStatusBadge from '@/components/ui/NStatusBadge.vue'

function mountBadge(kind: string, status: string) {
  return mount(NStatusBadge, { props: { kind, status } as never })
}

describe('NStatusBadge', () => {
  it('показывает подпись статуса, с которым смонтирован', () => {
    expect(mountBadge('event', 'draft').text()).toBe('Черновик')
    expect(mountBadge('event', 'published').text()).toBe('В продаже')
  })

  it('пересчитывает подпись, когда статус меняется на живом экране', async () => {
    const wrapper = mountBadge('event', 'draft')
    expect(wrapper.text()).toBe('Черновик')

    await wrapper.setProps({ status: 'published' })

    expect(wrapper.text()).toBe('В продаже')
  })

  it('пересчитывает подпись в обе стороны — это не одноразовый переход', async () => {
    // Публикация, отмена и повторная публикация — обычный сценарий дня.
    // Проверка в одну сторону прошла бы и на «обновляется только из draft».
    const wrapper = mountBadge('event', 'draft')

    await wrapper.setProps({ status: 'published' })
    expect(wrapper.text()).toBe('В продаже')

    await wrapper.setProps({ status: 'cancelled' })
    expect(wrapper.text()).toBe('Отменён')

    await wrapper.setProps({ status: 'published' })
    expect(wrapper.text()).toBe('В продаже')
  })

  it('пересчитывает цвет вместе с подписью, а не только текст', async () => {
    // Подпись и цвет привязаны к одной машине состояний: если обновить одно и
    // забыть другое, «Отменён» окажется зелёным — то есть сообщение станет
    // ровно противоположным по смыслу.
    const wrapper = mountBadge('event', 'published')
    const publishedClasses = wrapper.find('span').classes().join(' ')

    await wrapper.setProps({ status: 'cancelled' })
    const cancelledClasses = wrapper.find('span').classes().join(' ')

    expect(cancelledClasses).not.toBe(publishedClasses)
    expect(publishedClasses).toContain('mint')
    expect(cancelledClasses).toContain('rose')
  })

  it('переключает набор статусов вместе с типом сущности', async () => {
    // `kind` тоже проп: тот же код `cancelled` у события и у заказа означает
    // разное, и после смены типа бейдж не должен остаться на старом словаре.
    const wrapper = mountBadge('event', 'published')
    expect(wrapper.text()).toBe('В продаже')

    await wrapper.setProps({ kind: 'order', status: 'cancelled' })
    expect(wrapper.text()).toBe('Отменён')

    await wrapper.setProps({ kind: 'order', status: 'paid' })
    expect(wrapper.text()).toBe('Оплачен')
  })

  it('на неизвестном статусе показывает сам код, а не пустую ячейку', async () => {
    const wrapper = mountBadge('event', 'archived')

    // `archived` есть в БД, но отсутствует в словаре EVENT: молчать об этом
    // хуже, чем показать код.
    expect(wrapper.text()).toBe('archived')

    await wrapper.setProps({ status: 'draft' })
    expect(wrapper.text()).toBe('Черновик')
  })
})
