/**
 * История изменений схемы зала (undo/redo) для редактора.
 *
 * Извлечено из HallEditorPage.vue: логика снапшотов не зависит от Konva и
 * панелей, поэтому вынесена в композабл. Снимок всегда берётся ДО изменения —
 * иначе undo возвращает уже изменённое состояние (исторический дефект).
 */
import { ref, type Ref } from 'vue'
import type { EBackground, ESector, EStatic, SchemaSnapshot } from './editorTypes'

const HISTORY_LIMIT = 50

function cloneSchema(sectors: ESector[], statics: EStatic[], backgrounds: EBackground[]): SchemaSnapshot {
  return JSON.parse(JSON.stringify({ sectors, statics, backgrounds })) as SchemaSnapshot
}

export interface EditorHistoryState {
  sectors: Ref<ESector[]>
  statics: Ref<EStatic[]>
  backgrounds: Ref<EBackground[]>
}

export function useEditorHistory(state: EditorHistoryState) {
  const history = ref<SchemaSnapshot[]>([])
  const future = ref<SchemaSnapshot[]>([])

  /** Флаг «снапшот до начала перемещения группы» (Konva мутирует модель напрямую). */
  let moveSnapshotTaken = false

  const canUndo = () => history.value.length > 0
  const canRedo = () => future.value.length > 0

  /** Снапшот ДО изменения. */
  function snapshot(): void {
    history.value.push(cloneSchema(state.sectors.value, state.statics.value, state.backgrounds.value))
    future.value = []
    if (history.value.length > HISTORY_LIMIT) history.value.shift()
  }

  /** Начало drag-перемещения: снапшот «до» берётся один раз на начало. */
  function beginMoveSnapshot(): void {
    if (!moveSnapshotTaken) {
      snapshot()
      moveSnapshotTaken = true
    }
  }

  /** Конец drag-перемещения: разрешаем следующий снапшот «до» (дефект A8). */
  function endMoveSnapshot(): void {
    moveSnapshotTaken = false
  }

  function undo(): SchemaSnapshot | null {
    const prev = history.value.pop()
    if (!prev) return null
    future.value.push(cloneSchema(state.sectors.value, state.statics.value, state.backgrounds.value))
    return prev
  }

  function redo(): SchemaSnapshot | null {
    const next = future.value.pop()
    if (!next) return null
    history.value.push(cloneSchema(state.sectors.value, state.statics.value, state.backgrounds.value))
    return next
  }

  return { history, future, canUndo, canRedo, snapshot, beginMoveSnapshot, endMoveSnapshot, undo, redo }
}

export type EditorHistory = ReturnType<typeof useEditorHistory>
