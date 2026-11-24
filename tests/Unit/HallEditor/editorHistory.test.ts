import { describe, expect, it } from 'vitest'
import { ref } from 'vue'
import { useEditorHistory } from '@/pages/hall-editor/useEditorHistory'

/** История редактора: снимок ДО мутации, один снимок на жест, сброс при смене версии. */
describe('useEditorHistory', () => {
  function makeState() {
    return {
      canvas: ref({ width: 900, height: 520 }),
      sectors: ref([{ id: 's1', x: 10, y: 10 }]),
      statics: ref([]),
      backgrounds: ref([]),
    }
  }

  it('undo возвращает состояние до изменения, включая размер холста', () => {
    const state = makeState()
    const history = useEditorHistory(state as never)

    history.snapshot()
    state.sectors.value = [{ id: 's1', x: 99, y: 99 }]
    state.canvas.value = { width: 400, height: 600 }

    const prev = history.undo()!
    expect(prev.sectors[0].x).toBe(10)
    expect(prev.canvas).toEqual({ width: 900, height: 520 })
    expect(history.canRedo()).toBe(true)
  })

  it('один drag даёт один снимок, пока не вызван endMoveSnapshot', () => {
    const state = makeState()
    const history = useEditorHistory(state as never)

    history.beginMoveSnapshot()
    state.sectors.value[0].x = 30
    history.beginMoveSnapshot()
    state.sectors.value[0].x = 60
    expect(history.history.value).toHaveLength(1)

    history.endMoveSnapshot()
    history.beginMoveSnapshot()
    expect(history.history.value).toHaveLength(2)
  })

  it('reset очищает историю и флаг жеста (смена серверной версии)', () => {
    const state = makeState()
    const history = useEditorHistory(state as never)

    history.snapshot()
    history.undo()
    expect(history.canRedo()).toBe(true)
    history.beginMoveSnapshot()

    history.reset()
    expect(history.history.value).toEqual([])
    expect(history.future.value).toEqual([])
    expect(history.canUndo()).toBe(false)
    expect(history.canRedo()).toBe(false)

    // После reset следующий жест снова обязан создать снимок «до».
    history.beginMoveSnapshot()
    expect(history.history.value).toHaveLength(1)
  })

  it('история ограничена 50 снимками', () => {
    const state = makeState()
    const history = useEditorHistory(state as never)
    for (let i = 0; i < 60; i += 1) history.snapshot()
    expect(history.history.value).toHaveLength(50)
  })
})
