/**
 * Склеивает сборку предпросмотра в один HTML-файл.
 *
 * Запуск: node tools/inline-preview.mjs
 * Результат: dist-preview/ui-preview.html — открывается двойным кликом,
 * без сервера и без внешних запросов.
 */
import { readFileSync, writeFileSync, existsSync } from 'node:fs'
import { join, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = join(dirname(fileURLToPath(import.meta.url)), '..')
const outDir = join(root, 'dist-preview')
const entry = join(outDir, 'index.html')

if (!existsSync(entry)) {
  console.error('Нет dist-preview/index.html — сначала выполните `npm run build:preview`')
  process.exit(1)
}

let html = readFileSync(entry, 'utf8')

// Стили — в <style>.
html = html.replace(/<link[^>]+rel="stylesheet"[^>]*href="([^"]+)"[^>]*>/g, (_match, href) => {
  const css = readFileSync(join(outDir, href.replace(/^\.?\//, '')), 'utf8')
  return `<style>\n${css}\n</style>`
})

// Скрипт — в <script type="module">.
html = html.replace(/<script[^>]+src="([^"]+)"[^>]*><\/script>/g, (_match, src) => {
  const js = readFileSync(join(outDir, src.replace(/^\.?\//, '')), 'utf8')
  return `<script type="module">\n${js}\n</script>`
})

const target = join(outDir, 'ui-preview.html')
writeFileSync(target, html, 'utf8')

const kb = (Buffer.byteLength(html, 'utf8') / 1024).toFixed(0)
console.log(`ui-preview.html готов: ${kb} КБ`)
