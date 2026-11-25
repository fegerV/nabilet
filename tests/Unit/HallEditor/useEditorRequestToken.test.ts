import { describe, expect, it } from 'vitest'
import { useEditorRequestToken } from '../../../resources/js/pages/hall-editor/composables/useEditorRequestToken'

describe('useEditorRequestToken', () => {
  it('invalidates responses captured before a hall switch', () => {
    const guard = useEditorRequestToken()
    const oldRequest = guard.current()

    guard.next()

    expect(guard.isCurrent(oldRequest)).toBe(false)
    expect(guard.isCurrent(guard.current())).toBe(true)
  })

  it('keeps the token stable while the editor stays on the same hall', () => {
    const guard = useEditorRequestToken()
    const token = guard.current()

    expect(guard.isCurrent(token)).toBe(true)
    expect(guard.current()).toBe(token)
  })

  it('invalidates every earlier token after several reloads', () => {
    const guard = useEditorRequestToken()
    const first = guard.current()
    const second = guard.next()
    guard.next()

    expect(guard.isCurrent(first)).toBe(false)
    expect(guard.isCurrent(second)).toBe(false)
  })
})
