/**
 * Окружение тестов витрины.
 *
 * ЗАЧЕМ ЭТОТ ФАЙЛ
 *   jsdom не реализует `window.matchMedia`, а `stores/ui.ts` спрашивает
 *   системную тему в момент создания стора. Без заглушки любой тест, который
 *   монтирует компонент, трогающий ui-стор (уведомления, тема), падал бы на
 *   «window.matchMedia is not a function» — то есть по причине окружения,
 *   а не кода. Заглушка одна на все тесты: здесь, а не в каждом файле.
 *
 * `matches: false` — системная тема считается светлой. Сами темы проверяются
 * отдельно, если появится такая нужда.
 */
if (typeof window !== 'undefined' && typeof window.matchMedia !== 'function') {
  window.matchMedia = ((query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: () => {},
    removeListener: () => {},
    addEventListener: () => {},
    removeEventListener: () => {},
    dispatchEvent: () => false,
  })) as unknown as typeof window.matchMedia
}
