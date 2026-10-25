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
 * ── ПОЧЕМУ ШАГ «ВЫРЕЗАТЬ НЕ-КОЛОНОЧНЫЕ ПОЛЯ» ТОЖЕ ЗДЕСЬ ───────────────────
 *
 * В прошлой правке он был оставлен потребителю с обоснованием «правило у
 * создания и правки своё: разные побочные ключи и разные тексты в лог». Скан
 * дублированных списков показал, что это неправда: список полей был
 * ПОБАЙТОВО одинаковым в двух классах, и тело цикла тоже — различались ровно
 * две вещи: текст сообщения в лог и одна служебная строка.
 *
 * Цена копии видна там же, где её искали: `storeUploadedPoster()` ставит
 * служебный `__poster_stored` в ОБОИХ классах (трейт общий), а вырезал его
 * только Store. На правке синтетический ключ оставался во входном наборе —
 * ровно то, от чего предостерегает докблок `stripStatusField()`: значение
 * лежит и выглядит принятым. Сейчас он вырезается в одном месте и на обоих
 * путях.
 *
 * Потребителю остался один хук — `reportDroppedFields()`: у создания и правки
 * действительно разные тексты (и на правке важен `event_id` — ЧЬЯ карточка
 * потеряла поля). Всё остальное — список, накопление, вырезание из обоих
 * источников — принадлежит трейту.
 */
trait PreparesEventPayload
{
    use HandlesPosterUpload;

    /**
     * Поля, которые принимает API (для совместимости со старыми клиентами), но
     * которых НЕТ в таблице `events` (миграция 000200_002_content). Они
     * валидируются, но вырезаются из payload перед Event::create() — иначе
     * Eloquent ушёл бы в 500 на несуществующую колонку. Даты живут в
     * sessions/event_dates, цены — в inventory_row_prices.
     *
     * ЭТИ ПОЛЯ БОЛЬШЕ НЕ ТЕРЯЮТСЯ МОЛЧА (см. `droppedFields()`). Раньше клиент,
     * отправивший `start_date`, получал 201 и был уверен, что дата сохранена —
     * а её не было нигде. Теперь имена отброшенных полей возвращаются в
     * `meta.ignored_fields`, а факт пишется в лог. Поведение то же (поля
     * по-прежнему не пишутся), но перестало быть невидимым.
     *
     * @var list<string>
     */
    protected const NON_COLUMN_FIELDS = ['start_date', 'end_date', 'timezone', 'currency', 'is_featured', 'min_price', 'max_price', 'metadata'];

    /**
     * Служебные ключи, которых нет ни в одной таблице: их ставит сам код
     * (`storeUploadedPoster()` — признак «файл уже записан»).
     *
     * В `droppedFields()` они не попадают: это не данные клиента, терять
     * нечего. Но во входном наборе им делать нечего по той же причине, что и
     * `status`: значение лежит и выглядит принятым.
     *
     * @var list<string>
     */
    private const INTERNAL_FIELDS = ['__poster_stored'];

    /**
     * Что реально было отброшено: поле => значение.
     *
     * Только непустые значения: `null` и `''` в payload — это обычная форма
     * (поле есть в HTML-форме, но не заполнено), а не потеря данных. Сообщать о
     * них значило бы показывать предупреждение на каждом сохранении.
     *
     * @var array<string, mixed>
     */
    private array $droppedFields = [];

    /**
     * Имена полей, значения которых некуда было записать.
     *
     * @return array<string, mixed>
     */
    public function droppedFields(): array
    {
        return $this->droppedFields;
    }

    /**
     * Сообщить о потерянных полях — текст у создания и правки свой.
     *
     * @param  list<string>  $fields
     */
    abstract protected function reportDroppedFields(array $fields): void;

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

    /**
     * Вырезать из входных данных всё, чего нет в таблице `events`.
     *
     * ВАЖНО, ЧТО ЭТО ЗАЩИЩАЕТ, А ЧТО НЕТ. `validated()` читает СНИМОК данных,
     * снятый до `passedValidation()`, поэтому этот метод НЕ влияет на его
     * результат — запись защищает `Event::$fillable`, а не он. Он защищает
     * чтения входного набора мимо `validated()`: `$request->input()`, логи,
     * отладка. Молчаливая потеря неотличима от успешного сохранения, поэтому
     * здесь же фиксируется, что именно было отброшено.
     *
     * Вырезается из ОБОИХ источников (`request` и `getInputSource()`): второй —
     * то, что читает `all()`, и оставить ключ только в нём значило бы
     * показывать разное разным читателям.
     */
    protected function stripNonColumnFields(): void
    {
        foreach (self::NON_COLUMN_FIELDS as $field) {
            $value = $this->input($field, '__missing__');

            // Пустое значение — это норма: поле присутствует в HTML-форме, но не
            // заполнено. Предупреждать о нём значит шуметь на каждом сохранении.
            if ($value !== '__missing__' && $value !== null && $value !== '' && $value !== []) {
                $this->droppedFields[$field] = $value;
            }

            $this->forget($field);
        }

        foreach (self::INTERNAL_FIELDS as $field) {
            $this->forget($field);
        }

        if ($this->droppedFields !== []) {
            $this->reportDroppedFields(array_keys($this->droppedFields));
        }
    }

    /** Убрать ключ из живого источника и из того, что читает `all()`. */
    private function forget(string $field): void
    {
        $this->request->remove($field);
        $this->getInputSource()->remove($field);
    }
}
