import { beforeEach, describe, expect, it } from 'vitest'
import { getEmbedToken, isEmbedMode } from '@/lib/embed-token'

/** Токен в адресе рамки — единственный источник capability для embed API. */
describe('embed token', () => {
  beforeEach(() => {
    window.history.replaceState({}, '', '/event/metallica-vavilon-surgut')
  })

  it('reads the token from the query string', () => {
    window.history.replaceState({}, '', '/event/x?embed_token=nbem_123&theme=dark')
    expect(getEmbedToken()).toBe('nbem_123')
    expect(isEmbedMode()).toBe(true)
  })

  it('reads the legacy hash form', () => {
    window.history.replaceState({}, '', '/#/?embed_token=nbem_hash')
    expect(getEmbedToken()).toBe('nbem_hash')
  })

  it('is empty on the ordinary storefront', () => {
    expect(getEmbedToken()).toBe('')
    expect(isEmbedMode()).toBe(false)
  })
})
