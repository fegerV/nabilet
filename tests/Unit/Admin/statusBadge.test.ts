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

  it('подписывает все шесть состояний мероприятия, а не только два', () => {
    // Раньше здесь стояло `archived` в ожидании СЫРОГО кода: тип `EventStatus`
    // содержал `sold_out` и `finished`, которых бэкенд не знает, и не содержал
    // `scheduled` / `completed` / `archived`. Поэтому три реальных состояния
    // показывались английским кодом, а две записи словаря были мёртвыми.
    //
    // Источник истины — `EventStatus::all()`; тот же список закреплён в схеме
    // (миграция `015_events_status_check.sql`), и `tools/verify-state-machines.php`
    // сверяет машину с CHECK на каждом прогоне.
    expect(mountBadge('event', 'draft').text()).toBe('Черновик')
    expect(mountBadge('event', 'scheduled').text()).toBe('Запланирован')
    expect(mountBadge('event', 'published').text()).toBe('В продаже')
    expect(mountBadge('event', 'completed').text()).toBe('Завершён')
    expect(mountBadge('event', 'cancelled').text()).toBe('Отменён')
    expect(mountBadge('event', 'archived').text()).toBe('Архив')
  })

  it('на неизвестном статусе показывает сам код, а не пустую ячейку', async () => {
    // Словарь EVENT теперь закрыт полностью, но запасной вариант обязан
    // остаться: API может отдать статус, которого эта сборка ещё не знает, а
    // пустая ячейка в таблице хуже кода — по ней нечего искать в бэкенде.
    const wrapper = mountBadge('event', 'something_new')

    expect(wrapper.text()).toBe('something_new')

    await wrapper.setProps({ status: 'draft' })
    expect(wrapper.text()).toBe('Черновик')
  })
})
