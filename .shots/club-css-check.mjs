// Есть ли в собранном CSS все классы, которые использует страница клуба.
// Ловушка проекта: `bg-gradient-brand` вместо `bg-brand-gradient` не даёт
// НИКАКОГО правила — элемент остаётся без фона, и это видно только глазами.
import fs from 'node:fs'
import path from 'node:path'

const dir = 'dist/assets'
const css = fs
  .readdirSync(dir)
  .filter((f) => f.endsWith('.css'))
  .map((f) => fs.readFileSync(path.join(dir, f), 'utf8'))
  .join('\n')

const esc = (c) => '.' + c.replace(/([/[\]().%#:])/g, '\\$1')

const classes = [
  'bg-brand-gradient',
  'bg-stage',
  'bg-white/15',
  'bg-white/25',
  'bg-white/20',
  'bg-white/90',
  'text-white/70',
  'text-white/75',
  'text-2xs',
  'font-display',
  'surface-card',
  'bg-brand-500/10',
  'text-brand-400',
  'bg-mint-500/10',
  'text-mint-400',
  'bg-accent-500/10',
  'text-accent-400',
  'text-content',
  'text-subtle',
  'text-muted',
  'bg-surface-3',
  'bg-surface-2',
  'shadow-brand',
  'ring-brand-500/25',
  'text-brand-300',
  'bg-mint-500/15',

  'text-sun-400',
  'z-modal',
  'bg-overlay/70',
  'max-w-content',
  'duration-320',
  'animate-pulse-ring',
  'animate-scale-in',
  'text-balance',
  'backdrop-blur',
]

const missing = []
for (const c of classes) {
  if (!css.includes(esc(c))) missing.push(c)
}
console.log('CSS файлов:', fs.readdirSync(dir).filter((f) => f.endsWith('.css')).length)
console.log('проверено классов:', classes.length)
console.log('НЕ НАЙДЕНО:', missing.length ? missing.join(', ') : '— нет —')

// Контрольная проверка на «перевёрнутые» имена: таких классов быть не должно.
for (const bad of ['bg-gradient-brand', 'bg-gradient-stage', 'text-content-subtle']) {
  console.log(`ложный класс ${bad}:`, css.includes(esc(bad)) ? 'ЕСТЬ (плохо)' : 'нет (хорошо)')
}
