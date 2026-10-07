<?php

declare(strict_types=1);

namespace Nabilet\Modules\Notifications\Http\Controllers;

use App\Models\NotificationTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\HtmlString;
use Nabilet\Modules\Notifications\Services\TransactionalMailService;

/**
 * Редактирование шаблонов транзакционных писем.
 *
 * Доступ — только администратор: шаблон определяет, что получит покупатель,
 * и ошибка в нём видна всем сразу.
 *
 * Шаблоны ОБЩИЕ на весь проект, а не на организацию: в схеме
 * `notification_templates` нет organization_id, и добавлять его сейчас значит
 * менять Core-спеку. Для стадии одной витрины это честнее, чем имитация
 * мультитенантности; когда появится вторая организация, код шаблона станет
 * составным.
 */
class NotificationTemplateController extends Controller
{
    public function __construct(
        private readonly TransactionalMailService $mail,
    ) {}

    public function index(): JsonResponse
    {
        $templates = NotificationTemplate::query()
            ->where('channel', TransactionalMailService::CHANNEL)
            ->orderBy('code')
            ->get();

        return response()->json([
            // get() во фронтенде читает полезную нагрузку из одного `data`;
            // список и словарь переменных — одна атомарная модель экрана.
            'data' => [
                'templates' => $templates
                    ->map(fn (NotificationTemplate $template): array => $this->present($template))
                    ->values(),
                'variables' => TransactionalMailService::availableVariables(),
            ],
        ]);
    }

    public function show(int $template): JsonResponse
    {
        return response()->json([
            'data' => $this->present($this->find($template)),
        ]);
    }

    public function update(Request $request, int $template): JsonResponse
    {
        $model = $this->find($template);

        $data = $request->validate([
            'subject' => ['sometimes', 'string', 'max:500'],
            'body_html' => ['sometimes', 'string', 'max:300000'],
            'body_text' => ['sometimes', 'string', 'max:100000'],
            'active' => ['sometimes', 'boolean'],
        ]);

        // code/channel/locale намеренно НЕ обновляются: это идентичность
        // шаблона. Обсервер ищет письмо по коду `order.<статус>`, поэтому
        // правка кода молча отключила бы рассылку.
        $model->update($data);

        return response()->json([
            'data' => $this->present($model->fresh() ?? $model),
        ]);
    }

    /**
     * Предпросмотр: тот же рендер, что уйдёт в письмо, но без отправки.
     *
     * Переменные можно передать свои; иначе подставляется демонстрационный
     * набор, чтобы администратор видел письмо, похожее на настоящее, а не
     * текст с незаменёнными `{{скобками}}`.
     */
    public function preview(Request $request, int $template): JsonResponse
    {
        $model = $this->find($template);

        // Предпросмотр должен отображать именно то, что редактор сейчас держит
        // в форме, включая несохранённые изменения. Перезапись только этой
        // загруженной модели не вызывает save() и не трогает постоянный шаблон.
        $draft = $request->validate([
            'subject' => ['sometimes', 'string', 'max:500'],
            'body_html' => ['sometimes', 'string', 'max:300000'],
            'body_text' => ['sometimes', 'string', 'max:100000'],
            'variables' => ['sometimes', 'array'],
        ]);

        foreach (['subject', 'body_html', 'body_text'] as $field) {
            if (array_key_exists($field, $draft)) {
                $model->{$field} = $draft[$field];
            }
        }

        // Все значения из запроса — текст и будут HTML-экранированы рендерером.
        // tickets_html — единственное исключение, его разрешено вставить как
        // HTML только из этого контроллера (демо-данные) или из OrderNotificationData.
        $variables = $this->sampleVariables();
        $incoming = $draft['variables'] ?? [];

        foreach ($incoming as $name => $value) {
            if ($name === 'tickets_html') {
                continue;
            }

            if (in_array($name, \Nabilet\Modules\Notifications\Services\TransactionalMailService::availableVariables(), true)
                && ($value === null || is_scalar($value))) {
                $variables[$name] = $value;
            }
        }

        return response()->json([
            'data' => $this->mail->renderTemplate($model, $variables),
        ]);
    }

    private function find(int $id): NotificationTemplate
    {
        return NotificationTemplate::query()->findOrFail($id);
    }

    /** @return array{id: int, code: string, channel: string, locale: string, subject: string|null, body_html: string|null, body_text: string|null, active: bool} */
    private function present(NotificationTemplate $template): array
    {
        return [
            'id' => $template->id,
            'code' => $template->code,
            'channel' => $template->channel,
            'locale' => $template->locale,
            'subject' => $template->subject,
            'body_html' => $template->body_html,
            'body_text' => $template->body_text,
            'active' => (bool) $template->active,
        ];
    }

    /** @return array<string, string|HtmlString> */
    private function sampleVariables(): array
    {
        return [
            'customer_name' => 'Иван Петров',
            'order_number' => 'NB-20261007-DEMO0001',
            'event_name' => 'Концерт симфонического оркестра',
            'event_date' => '20.11.2026 19:00',
            'venue_name' => 'ДК «Нефтяник»',
            'total' => '2 500,00 ₽',
            'tickets_html' => new HtmlString('<div style="border:1px dashed #cbd5e1;padding:12px;">'
                . 'Билет NB-20261007-0001 — место Партер 1 / 12</div>'),
            'tickets_text' => 'Билет NB-20261007-0001 — место Партер 1 / 12',
        ];
    }
}
