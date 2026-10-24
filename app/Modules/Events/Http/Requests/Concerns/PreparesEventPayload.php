<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\Http\Requests\Concerns;

/**
 * Подготовка payload мероприятия: один порядок шагов на Store и Update.
 *
 * ЗАЧЕМ. Одна и та же последовательность из четырёх шагов была выписана
 * трижды: в `StoreEventRequest::preparePayload()` и ДВАЖДЫ в
 * `UpdateEventRequest` — отдельно в `passedValidation()`, отдельно в
 * `validated()`. Третий экземпляр и был дефектом: «Belt and braces»
 * (`Validator::validated()` читает ЖИВОЙ источник данных...) держится на том,
 * что оба метода делают одно и то же. Добавьте пятый шаг в `passedValidation()`
 * и забудьте про `validated()` — и сохранение карточки пойдёт по другому пути,
 * чем ответ API, молча.
 *
 * Теперь порядок шагов существует в одном месте, вместе с двумя входными
 * точками (`passedValidation()` и `validated()`), которые его зовут.
 *
 * ── ДЕФЕКТ, КОТОРЫЙ ВСКРЫЛО ИЗВЛЕЧЕНИЕ ────────────────────────────────────
 *
 * `Illuminate\Validation\Validator::validated()` читает `$this->data` — СНИМОК
 * входных данных, снятый в момент создания валидатора. А
 * `ValidatesWhenResolvedTrait::validateResolved()` создаёт валидатор РАНЬШЕ,
 * чем зовёт `passedValidation()`. Поэтому всё, что `preparePayload()` кладёт в
 * живой источник входных данных, видно в `$request->input()`, но НЕ видно в
 * `$request->validated()` — а контроллер пишет именно `validated()`.
 *
 * Следствие, проверенное тестом (`EventPosterMappingTest`): афиша не
 * сохранялась НИКОГДА, ни по `image_url`, ни загруженным файлом.
 *
 *   - `image_url` → `poster` (перенос делает `normalizePosterMapping()`) —
 *     потерян: `poster` есть в правилах, но в снимке его нет, а `image_url`
 *     в снимке есть, однако в `Event::$fillable` его нет, и Eloquent молча
 *     его выбрасывает.
 *   - `poster_file` → файл на диске + URL в `poster` — потерян так же, причём
 *     файл при этом УЖЕ записан: ответ 201, колонка NULL, мусор на диске.
 *
 * Отсюда две правки, и они разные по природе:
 *
 *  1. `normalizePosterMapping()` — ЧИСТАЯ нормализация входа (без диска и без
 *     сети). Ей место в `prepareForValidation()`: этот хук `validateResolved()`
 *     зовёт ДО создания валидатора, поэтому `poster` попадает в снимок и
 *     доезжает до `validated()` штатным путём.
 *  2. `storeUploadedPoster()` пишет файл на диск, и делать это до прохождения
 *     валидации нельзя: неудачная загрузка оставила бы сироту. Она остаётся в
 *     `passedValidation()`, а `validated()` забирает её результат из живого
 *     источника — единственное место, где снимок и живой источник разошлись
 *     по нашей вине.
 *
 * Почему трейт, а не метод в каждом классе: шаги 1–2 («афиша») уже были общими
 * (`HandlesPosterUpload`), шаг 4 (`stripStatusField()`) был побайтовой копией
 * в двух классах. Остался единственный шаг, который по смыслу РАЗНЫЙ:
 * `stripNonColumnFields()` — Store дополнительно убирает служебный
 * `__poster_stored`, а сообщения в лог у них разные (создание против
 * правки). Он остаётся у потребителя; трейт его требует.
 */
trait PreparesEventPayload
{
    use HandlesPosterUpload;

    /**
     * Поля без колонок в `events`. Правило у потребителя — своё, и это
     * осознанно: у создания и правки разные побочные ключи и разные тексты
     * в лог. Здесь объявлен только факт, что шаг обязан существовать.
     */
    abstract protected function stripNonColumnFields(): void;

    /**
     * Чистая нормализация входа — ДО снимка данных валидатора.
     *
     * Только `normalizePosterMapping()`: перенос `image_url` → `poster` не
     * трогает ни диск, ни сеть, поэтому безопасен до валидации. Файл
     * (`storeUploadedPoster()`) здесь появляться не должен по причине выше.
     */
    protected function prepareForValidation(): void
    {
        $this->normalizePosterMapping();
    }

    /**
     * Единственный порядок шагов. Идемпотентен: каждый шаг безопасно
     * вызывать повторно (удалённые ключи исчезают из `all()` и не
     * обрабатываются снова, `__poster_stored` гасит повторное сохранение
     * файла).
     */
    protected function preparePayload(): void
    {
        $this->storeUploadedPoster();
        $this->normalizePosterMapping();
        $this->stripNonColumnFields();
        $this->stripStatusField();
    }

    public function passedValidation(): void
    {
        $this->preparePayload();
    }

    /**
     * Второй вход в подготовку payload — «страховка», если пайп или наследник
     * проскочил `passedValidation()`.
     *
     * Раньше эти два метода были выписаны в каждом Request отдельно, и в
     * `UpdateEventRequest` — дважды подряд. Пятый шаг, добавленный в один и
     * забытый в другом, молча отправлял бы сохранение карточки и ответ API
     * разными путями.
     *
     * @return array<string, mixed>|mixed
     */
    public function validated($key = null, $default = null)
    {
        $this->preparePayload();

        $data = parent::validated($key, $default);

        // Результат `storeUploadedPoster()` — URL только что записанного
        // файла. Он попадает в живой источник уже ПОСЛЕ снимка валидатора,
        // поэтому `parent::validated()` его не видит. Забираем из живого
        // источника: иначе загруженная афиша не доезжает до колонки.
        if ($key === null && is_array($data) && $this->input('poster') !== null) {
            $data['poster'] = $this->input('poster');
        }

        return $data;
    }

    /**
     * Убирает `status` из входных данных.
     *
     * ЭТО НЕ ТО, ЧТО ЗАЩИЩАЕТ ЗАПИСЬ. Защищает отсутствие правила `status` в
     * `rules()`: `Validator::validated()` перебирает `getRules()` и читает
     * значения из снимка данных, поэтому удаление ключа из входного набора в
     * результат не попадает. Тот, кто вернёт правило и понадеется на этот
     * метод, откроет обход политики снова.
     *
     * Метод нужен для второго: `$request->input('status')` читают мимо
     * `validated()` — логи, отладка, будущий код. Пока значение лежит во входном
     * наборе, оно выглядит как принятое. Одинаков на создании и на правке
     * намеренно: симметрия здесь важнее экономии четырёх строк, расхождение
     * этих двух классов и было причиной дефекта.
     */
    protected function stripStatusField(): void
    {
        $this->request->remove('status');
        $this->getInputSource()->remove('status');
    }
}
