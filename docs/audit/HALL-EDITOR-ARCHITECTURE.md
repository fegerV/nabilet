# Архитектура конструктора залов — текущее состояние

## 1. Что есть

```
resources/js/pages/hall-editor/
├── HallEditorPage.vue      ~4130 строк — состояние, сцена, панели, операции
├── editorTypes.ts          типы ESector/EStatic/EBackground/SchemaSnapshot
├── hallEditorFields.ts     поля форм
├── seatGeometry.ts         генерация сетки/дуги/стола, пере numbering
├── schemaSerialization.ts  экспорт/импорт/валидация JSON
├── schemaImport.ts         тонкая обёртка импорта
├── useEditorHistory.ts     undo/redo
└── composables/
    └── useEditorRequestToken.ts  защита от запоздавших ответов API (新增)
```

Рекомендуемая структура из ТЗ (`components/`, `composables/`, `domain/`, `types/`) пока не реализована.

## 2. Что изменилось в этом аудите

- Выделен чистый composable `useEditorRequestToken`: `current()`, `next()`, `isCurrent()`. Он убирает прямую работу с счётчиком внутри компонента и покрыт юнит-тестом.
- В `HallEditorPage.vue` добавлена инвалидация токена при `loadFromServer()` и проверка перед применением ответа autosave. Это устраняет перезапись состояния другой площадки запоздавшим ответом.
- В `lib/danceZone.ts` выделена чистая функция `danceZoneBoxAt()` — размещение зоны по координатам схемы; покрыта тестами.

## 3. Почему полный рефакторинг не выполнен в этой ветке

`HallEditorPage.vue` — один компонент на 4130 строк с тесной связью реактивного состояния, Konva-сцены, панелей и сетевых операций. Механический перенос частей в 15–20 файлов без изменения контрактов не уменьшает связанность, но резко повышает риск регрессии в инструментах, которые в этой ветке не имеют браузерного покрытия. ТЗ прямо требует сохранить внешний вид и возможности редактора; поэтому разумнее провести декомпозицию отдельным этапом с параллельным E2E-покрытием.

## 4. План декомпозиции (следующий этап)

1. `useEditorPersistence.ts` — вынести `persistSchema`, `flushAutosave`, токен, обработку 409/422.
2. `useEditorSelection.ts` / `useEditorTools.ts` — выделение и инструменты.

3. `EditorCanvas.vue` + `useCanvasRenderer.ts` — инкрементальное обновление Konva вместо полной пересборки сцены.
4. `ObjectProperties.vue` и дочерние панели — вместо инспектора внутри page.
5. `ValidationPanel.vue` — отображение `details.objects` с подсветкой объекта на холсте.
6. `domain/geometry`, `domain/serialization` — перенести чистые функции из `seatGeometry.ts`/`schemaSerialization.ts`.

Критерий готовности этапа: `npm run typecheck`, Vitest и браузерный прогон всех инструментов из раздела 7 ТЗ до и после каждого переноса.

## 5. Производительность

См. `PERFORMANCE-REPORT.md` — замеры не проводились, вымышленных цифр в отчёте нет.
</think:6124c78e><tool_call:6124c78e>edit<arg_key:6124c78e>path</arg_key:6124c78e><arg_value:6124c78e>C:\Project\nabilet\docs\audit\HALL-EDITOR-ARCHITECTURE.md