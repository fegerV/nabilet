-- ============================================================ 026 — privacy consent/request guards
-- Замыкание дефектов §3.15 (REVIEW-spec-bundle.md) в таблицах 152-ФЗ.
--
-- consents (согласие субъекта на обработку данных):
--   `withdrawn_at` — момент, когда согласие перестало действовать. Без него
--   строка `withdrawn` не отвечает на единственный юридически важный вопрос
--   («когда?»), тот же класс дефекта, что `tickets.revoked_at` (§3.11).
--   Журнальная модель сохраняется: отзыв пишет НОВУЮ строку со статусом
--   `withdrawn`, и она обязана нести момент отзыва в себе
--   (`ck_consents_withdrawn_has_moment`), а не полагаться на соседнюю.
--
--   СЛЕДСТВИЕ РЕЦЕПТА §3.15, КОТОРОЕ НЕ ПРИМЕНЕНО (и почему). Предложенный там
--   CHECK «ровно один субъект» — `(user_id IS NOT NULL) <> (anonymous_id IS
--   NOT NULL)` — MySQL 8.4 отвергает ошибкой 3823: колонка `user_id` нужна
--   referential action'у внешнего ключа `fk_consents_user … ON DELETE SET NULL`,
--   а колонки, на которые ссылается referential action, в CHECK использовать
--   нельзя. Снять ограничение триггером тоже нельзя: MySQL не активирует
--   триггеры для FK-действий, то есть «ничейные» строки, порождённые самим
--   `SET NULL` при удалении пользователя, триггер не увидит. Инвариант остаётся
--   доменным: `ConsentRecord` отвергает и «никого», и «обоих»
--   (INVALID_CONSENT_SUBJECT) при конструировании каждой записи; чтобы сделать его
--   базовым, нужно менять семантику FK (RESTRICT + ручное разъединение при
--   erasure) — отдельное решение, не входящее в этот шаг.
--
-- privacy_requests (обращение субъекта: доступ/удаление/исправление…):
--   `due_at` — записанный срок ответа. Домен считает `dueAt(slaDays)`
--   параметром (ни ТЗ, ни контракт не фиксируют период — значение передаётся,
--   а не выдумывается, см. `PrivacyRequest::dueAt()`), но склеп для записанного
--   числа нужен в момент создания запроса: срок, который нельзя записать,
--   нельзя и проконтролировать. Кто создаёт строку — тот производит и срок.
--   `ck_privacy_requests_type` — словарь пяти видов обращений из домена
--   (`PrivacyRequest::types()`); как и со статусами (017), барьер становится
--   двойным — класс и база не могут разойтись, значения берутся из констант.
ALTER TABLE consents
  ADD COLUMN withdrawn_at DATETIME(6) NULL AFTER status,
  ADD CONSTRAINT ck_consents_withdrawn_has_moment CHECK (status <> 'withdrawn' OR withdrawn_at IS NOT NULL);

ALTER TABLE privacy_requests
  ADD COLUMN due_at DATETIME(6) NOT NULL AFTER status,
  ADD CONSTRAINT ck_privacy_requests_type CHECK (type IN ('access','erasure','rectification','restriction','portability'));

SET FOREIGN_KEY_CHECKS = 1;
