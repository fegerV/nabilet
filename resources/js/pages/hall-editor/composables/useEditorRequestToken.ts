/**
 * Защита редактора от запоздавших ответов API.
 *
 * Когда администратор переключает площадку (или перезагружает схему), запрос
 * autosave/PATCH может ещё быть в полёте. Его ответ содержит revision/id более
 * старого черновика; если применить его к новому редактору, следующий запрос
 * уйдёт с несуществующим base_revision и локальные правки будут потеряны.
 *
 * Токен — монотонный счётчик: каждый вызов `next()` делает все ранее выданные
 * токены недействительными.
 */
export interface RequestTokenGuard {
  /** Текущий токен (для нового запроса). */
  current(): number
  /** Инвалидировать все выданные токены и вернуть новый. */
  next(): number
  /** Относится ли ответ с токеном к текущей загрузке? */
  isCurrent(token: number): boolean
}

export function useEditorRequestToken(): RequestTokenGuard {
  let token = 0

  return {
    current: () => token,
    next: () => ++token,
    isCurrent: (candidate: number) => candidate === token,
  }
}
