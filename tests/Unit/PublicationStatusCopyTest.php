<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Support\PublicationStatus;
use Nabilet\Tests\Support\TestCase;

/**
 * Публикационный статус каталога: один владелец вместо семнадцати записей.
 *
 * ЧТО БЫЛО. Пять колонок — `organizations`, `event_categories`, `venues`,
 * `halls`, `artists` — принимают `active` / `inactive` (плюс `suspended` у
 * организации, см. ниже). Правило «что значит каждое слово» было выписано в
 * коде семнадцать раз, в четырёх разных формах:
 *
 *   1. готовой строкой правила — `in:active,inactive` в трёх правилах валидации;
 *   2. литералом в фильтре — `->where('status', 'active')` в десяти выборках;
 *   3. значением по умолчанию — `'status' => 'active'` при создании;
 *   4. сравнением — `$category->status !== 'active'` при перелинковке.
 *
 * ПОЧЕМУ ЭТО НЕ КОСМЕТИКА. Фильтр — это решение «показывать или нет», а не
 * украшение запроса. Переименуй значение (`active` → `published`), поправь
 * правила валидации — и каждая выборка продолжит искать слово, которого больше
 * нет. Симптом не похож на ошибку: витрина отвечает 200 с пустым списком
 * городов, `sitemap-venues.xml` отдаёт корректный XML без единого адреса,
 * страницы площадок и артистов отвечают 404. Ни исключения, ни строки в логе.
 *
 * ГДЕ ВИДНО, ЧТО ЭТО ОДНО ПРАВИЛО. В `CatalogService` четыре метода подряд —
 * `venueBySlug()`, `categoryBySlug()`, `organizationBySlug()`, `artistBySlug()` —
 * отличались ровно одной строкой `->where('status', 'active')` и именем модели.
 *
 * Сторож ловит все четыре формы. Формы 1, 2 и 4 однозначны. Форма 3 требует
 * исключения: `'status' => 'active'` пишется ещё и в `seats.status`, где словарь
 * ДРУГОЙ (`ck_seats_status`: `active|blocked|disabled`) и значит «место
 * продаётся», а не «площадка опубликована». Исключение перечислено с причиной и
 * проверяется на устаревание — молчаливое исключение со временем прикрыло бы
 * настоящую копию.
 *
 * ПОЧЕМУ ЭТО НЕ ДУБЛЬ `tools/verify-status-literals.php`. Тот гейт устроен
 * правильнее (токен-стрим, словарь по рефлексии владельца), но этот словарь он
 * покрыть НЕ МОЖЕТ по собственному замыслу: он включает значение в охрану
 * только тогда, когда во ВСЕЙ схеме его допускает ровно одна колонка. У `active`
 * таких колонок восемь (пользователи, организации, площадки, залы, места,
 * корзины, устройства контроля, розыгрыши), поэтому `active` не охраняется
 * вовсе — иначе `organizations.status` репортился бы как копия `carts.status`.
 * Причина записана в самом гейте: «шум — и шум заставляет гейт выключить».
 *
 * Здесь шума нет, потому что проверяется не значение, а ФОРМА его записи: четыре
 * фиксированных конструкции, из которых сегодня под исключением ровно одна
 * строка. Две охраны не спорят: гейт судит по значению, тест — по форме.
 *
 * Проверка ищет КОД, а не упоминания: докблоки и строчные комментарии вырезаются,
 * иначе объяснение «почему не здесь» само считалось бы нарушением.
 */
final class PublicationStatusCopyTest extends TestCase
{
    private const OWNER = '/Core/Support/PublicationStatus.php';

    /**
     * Где литерал `'status' => 'active'` — законный, потому что словарь другой.
     *
     * Карта «файл => подстрока, которая обязана в нём остаться». Подстрока нужна
     * как проверка на устаревание: если строку перепишут или файл удалят,
     * исключение перестанет что-либо прикрывать и тест обязан об этом сказать,
     * а не хранить мёртвую запись.
     *
     * @var array<string, string>
     */
    private const FOREIGN_STATUS_DEFAULTS = [
        'app/Modules/Inventory/Services/InventoryService.php' => "'status' => 'active',",
    ];

    public function testTheValidationRuleIsWrittenOnlyByItsOwner(): void
    {
        // Закрывающая кавычка в шаблоне обязательна: без неё он задел бы
        // `in:active,inactive,suspended` — а это ДРУГОЙ словарь (организация),
        // и запрещать его здесь значило бы сломать приостановку организации.
        $this->assertSame(
            [],
            $this->filesMatching('~in:active,inactive[\'"]~'),
            'словарь статуса выписан здесь строкой: берите PublicationStatus::validationRule()',
        );
    }

    public function testTheStatusFilterTakesTheValueFromItsOwner(): void
    {
        $this->assertSame(
            [],
            $this->filesMatching('~->where\(\s*[\'"](?:\w+\.)?status[\'"]\s*,\s*[\'"](?:active|inactive)[\'"]~'),
            'фильтр по статусу сравнивается с литералом: берите PublicationStatus::ACTIVE / publiclyVisible()',
        );
    }

    public function testTheStatusDefaultTakesTheValueFromItsOwner(): void
    {
        $offenders = [];

        foreach ($this->filesMatching('~[\'"]status[\'"]\s*=>\s*[\'"](?:active|inactive)[\'"]~') as $path) {
            if (isset(self::FOREIGN_STATUS_DEFAULTS[$path])) {
                continue;
            }

            $offenders[] = $path;
        }

        $this->assertSame(
            [],
            $offenders,
            'значение статуса по умолчанию выписано литералом: берите PublicationStatus::ACTIVE',
        );
    }

    public function testTheStatusComparisonTakesTheValueFromItsOwner(): void
    {
        $this->assertSame(
            [],
            $this->filesMatching('~(?:===|!==)\s*[\'"](?:active|inactive)[\'"]~'),
            'статус сравнивается с литералом: берите PublicationStatus::isPubliclyVisible()',
        );
    }

    /**
     * Исключение обязано оставаться нужным.
     *
     * Без этой проверки запись в `FOREIGN_STATUS_DEFAULTS` живёт вечно: строку
     * перепишут на константу, а исключение продолжит прикрывать этот файл — и
     * следующая настоящая копия в нём пройдёт незамеченной.
     */
    public function testTheForeignStatusDefaultExceptionIsStillNeeded(): void
    {
        foreach (self::FOREIGN_STATUS_DEFAULTS as $path => $needle) {
            $full = dirname(__DIR__, 2) . '/' . $path;

            $this->assertTrue(
                is_file($full),
                sprintf('исключение указывает на несуществующий файл: %s', $path),
            );

            $this->assertStringContainsString(
                $needle,
                (string) file_get_contents($full),
                sprintf('исключение устарело: в %s больше нет %s', $path, $needle),
            );
        }
    }

    /**
     * Половина «владелец на месте»: сам словарь обязан остаться тем же.
     *
     * Без этой проверки сторож выше зелен и тогда, когда правило собирается из
     * пустого списка: `in:` без значений не совпадёт ни с одной копией, но и
     * валидировать перестанет — любой статус пройдёт.
     */
    public function testTheOwnerStillDeclaresTheBaseVocabulary(): void
    {
        $this->assertSame(['active', 'inactive'], PublicationStatus::all());
        $this->assertSame('in:active,inactive', PublicationStatus::validationRule());
        $this->assertSame(['active'], PublicationStatus::publiclyVisible());
        $this->assertTrue(PublicationStatus::isPubliclyVisible(PublicationStatus::ACTIVE));
        $this->assertFalse(PublicationStatus::isPubliclyVisible(PublicationStatus::INACTIVE));
    }

    /**
     * ЗАКРЕПЛЁННЫЙ ФАКТ, который легко понять наоборот.
     *
     * У `organizations.status` словарь шире — `active|inactive|suspended`
     * (`ck_organizations_status`). Значит `all()` — БАЗОВЫЙ словарь, а не полный
     * список: `suspended` в нём отсутствует намеренно, и применять
     * `validationRule()` к организации НЕЛЬЗЯ.
     *
     * И в то же время `suspended` не виден в каталоге: вопрос «публиковать ли»
     * имеет один ответ у всех пяти колонок, даже когда списки допустимых
     * значений разные. Эти два факта легко перепутать местами — тест фиксирует
     * оба сразу, чтобы путаница стала красной, а не тихой.
     */
    public function testTheOrganisationThirdValueIsOutsideTheBaseVocabularyButStillHidden(): void
    {
        $this->assertFalse(
            in_array('suspended', PublicationStatus::all(), true),
            'базовый словарь не должен расширяться до словаря организации',
        );

        $this->assertFalse(
            str_contains(PublicationStatus::validationRule(), 'suspended'),
            'правило для площадки/зала не должно пропускать значение организации',
        );

        $this->assertFalse(
            PublicationStatus::isPubliclyVisible('suspended'),
            'приостановленная организация не показывается в каталоге',
        );
    }

    /**
     * Файлы `app/`, в коде которых встречается шаблон, кроме владельца.
     *
     * @return list<string>
     */
    private function filesMatching(string $pattern): array
    {
        $offenders = [];

        foreach ($this->appFiles() as $path) {
            if (str_ends_with($path, self::OWNER)) {
                continue;
            }

            if (preg_match($pattern, $this->code((string) file_get_contents($path))) === 1) {
                $offenders[] = $this->relative($path);
            }
        }

        return $offenders;
    }

    /** @return list<string> */
    private function appFiles(): array
    {
        $root = dirname(__DIR__, 2) . '/app';
        $paths = [];

        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($rii as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $paths[] = str_replace('\\', '/', $file->getPathname());
            }
        }

        sort($paths);

        return $paths;
    }

    /** Только код: блочные и строчные комментарии вырезаны. */
    private function code(string $source): string
    {
        return (string) preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], '', $source);
    }

    private function relative(string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen(dirname(__DIR__, 2)) + 1));
    }
}
