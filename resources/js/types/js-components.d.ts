/**
 * Объявления типов для JS-компонентов, потребляемых из TypeScript.
 *
 * ЗАЧЕМ ЭТО НУЖНО
 *
 * `tsconfig.json` включает `strict: true`, а `resources/js/**\/*.vue` попадает
 * в `include`. Компонент без `lang="ts"` не имеет выводимых типов, поэтому
 * импорт из TS-файла падает с TS7016 («implicitly has an 'any' type») — именно
 * так и произошло, когда `AdminTicketTemplatesPage.vue` начал монтировать
 * `TicketBuilder.vue`.
 *
 * ПОЧЕМУ ШИМ, А НЕ `lang="ts"` В САМОМ КОМПОНЕНТЕ
 *
 * `TicketBuilder.vue` — 1300 строк свободного JS (Konva-подобная работа с
 * холстом, десятки необъявленных параметров). Перевод на TS под `strict` +
 * `noImplicitAny` — это отдельная задача на весь компонент, а не побочный
 * эффект подключения раздела. Шим даёт проверку типов на границе (родитель
 * видит реальные пропы и события) и не притворяется, что внутри всё типизировано.
 *
 * Пропы описаны по `defineProps` самого компонента — при их изменении правьте
 * и здесь, иначе проверка начнёт врать.
 */
declare module '@/components/TicketBuilder.vue' {
  import type { DefineComponent } from 'vue'

  const TicketBuilder: DefineComponent<{
    /** id шаблона для редактирования; null — новый макет. */
    templateId?: number | string | null
    /** Не используется: организация берётся сервером из токена. */
    organizationId?: number | string | null
  }>

  export default TicketBuilder
}
