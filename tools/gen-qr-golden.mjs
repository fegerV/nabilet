/**
 * Генератор эталонных матриц QR для теста `QrEncoderGoldenMasterTest`.
 *
 * ЗАЧЕМ
 *
 * Свой QR-кодировщик на PHP (`app/Modules/Tickets/Domain/Qr/QrEncoder.php`)
 * нельзя проверить «на глаз»: неверные таблицы Reed-Solomon или маска дают
 * картинку, которая выглядит как QR, но не читается. Поэтому матрица
 * сравнивается с матрицей независимой реализации — npm-пакета `qrcode` (той же
 * библиотеки, что рисует QR в браузере покупателя).
 *
 * Запуск (нужен node и установленный `node_modules/qrcode`):
 *
 *     node tools/gen-qr-golden.mjs
 *
 * Результат — `tests/Fixtures/qr_golden.json`. Файл коммитится: PHP-тест должен
 * оставаться чистым, то есть не требовать Node в момент прогона.
 */

import { readFileSync, writeFileSync, mkdirSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import QRCode from 'qrcode'

const here = dirname(fileURLToPath(import.meta.url))
const outFile = resolve(here, '../tests/Fixtures/qr_golden.json')

/**
 * Длины выбраны так, чтобы задеть КАЖДУЮ поддерживаемую версию (1..10) и все
 * строки таблицы блоков Reed-Solomon. Границы посчитаны по ёмкости БАЙТОВОГО
 * режима уровня M: v1 = 14 байт, v2 = 26, v3 = 42, v4 = 62, v5 = 84, v6 = 106,
 * v7 = 122, v8 = 152, v9 = 180, v10 = 213.
 */
const lengths = [1, 14, 15, 26, 27, 42, 43, 62, 63, 84, 85, 106, 107, 122, 123, 152, 153, 180, 181, 200, 213]

/**
 * ВАЖНО: режим задаётся ЯВНО.
 *
 * `qrcode` подбирает режим сам и для строки из заглавных букв берёт
 * алфавитно-цифровой — он плотнее байтового, и версия получается другая. Мой
 * кодировщик умеет только байтовый режим, поэтому сравнение с авторежимом было
 * бы сравнением разных кодировок и не проверяло бы ничего. Явный
 * `mode: 'byte'` убирает эту неоднозначность.
 */
const asByteSegment = (payload) => [{ data: payload, mode: 'byte' }]

const payloads = [
  // Реалистичные билетные payload'ы: `NB1.<publicId>.<token>.<signature>`.
  'NB1.01M46GWH0K1J8N2P4Q6R8S0T2V.a1b2c3d4e5f6g7h8i9j0.signature',
  'NB1.01M4E2S27RZH0MFYMAR3VZ982X.9f8e7d6c5b4a3210.kQ7wX2mNp3',
  // Границы версий.
  ...lengths.map((len, index) => 'A'.repeat(len)),
  // Не-ASCII: байтовый режим обязан считать БАЙТЫ, а не символы.
  'Билет №12',
  'Тестовый концерт — партер, место 12',
]

const seen = new Set()
const cases = []

for (const payload of payloads) {
  if (seen.has(payload)) {
    continue
  }

  seen.add(payload)

  const qr = QRCode.create(asByteSegment(payload), { errorCorrectionLevel: 'M' })
  const size = qr.modules.size
  const rows = []

  for (let y = 0; y < size; y++) {
    let row = ''

    for (let x = 0; x < size; x++) {
      row += qr.modules.data[y * size + x] ? '1' : '0'
    }

    rows.push(row)
  }

  cases.push({
    payload,
    bytes: Buffer.byteLength(payload, 'utf8'),
    version: qr.version,
    size,
    rows,
  })
}

const qrcodeVersion = JSON.parse(
  readFileSync(resolve(here, '../node_modules/qrcode/package.json'), 'utf8'),
).version

const document = {
  source: `qrcode@${qrcodeVersion} (npm)`,
  errorCorrectionLevel: 'M',
  generatedBy: 'tools/gen-qr-golden.mjs',
  cases,
}

mkdirSync(dirname(outFile), { recursive: true })
writeFileSync(outFile, JSON.stringify(document, null, 2) + '\n')

const versions = [...new Set(cases.map((c) => c.version))].sort((a, b) => a - b)
console.log(`cases: ${cases.length}`)
console.log(`versions covered: ${versions.join(', ')}`)
console.log(`written: ${outFile}`)
