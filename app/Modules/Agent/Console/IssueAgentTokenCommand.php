<?php

declare(strict_types=1);

namespace Nabilet\Modules\Agent\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Nabilet\Core\Support\StaffRole;
use Nabilet\Modules\Core\Users\Models\User;

/**
 * Выпуск и отзыв токена внешнего ИИ-агента.
 *
 * Токен — Sanctum PAT с единственной ability `agent:read`, привязанный к
 * staff-пользователю: агент наследует роли владельца, а данные ограничены
 * организациями, где он состоит. Отдельного «пользователя-агента» не заводим:
 * сотрудник создаёт токен из СВОЕЙ учётки, и при увольнении/смене пароля
 * обычныйLifecycle сессий не отзывает PAT — отзыв токена отдельная команда.
 *
 * Открытый текст печатается ОДИН раз и только в консоль: в БД живёт sha256
 * (как и у всех токенов системы), повторно показать токен невозможно —
 * утерянный токен перевыпускается, а не восстанавливается.
 *
 * ПРИМЕРЫ
 *   php artisan nabilet:agent-token director@venue.ru
 *   php artisan nabilet:agent-token director@venue.ru --name="Дежурный агент"
 *   php artisan nabilet:agent-token director@venue.ru --revoke-all   # отозвать прежние
 *   php artisan nabilet:agent-token director@venue.ru --list         # показать активные
 */
class IssueAgentTokenCommand extends Command
{
    /** В строке abilities Sanctum хранит JSON-массив — совпадение подстрокой честно. */
    private const ABILITY = 'agent:read';

    protected $signature = 'nabilet:agent-token
        {email : E-mail staff-пользователя, от имени которого работает агент}
        {--name=AI agent : Название токена (видно владельцу в списке)}
        {--revoke-all : Отозвать все прежние agent-токены этого пользователя перед выпуском}
        {--list : Только показать активные agent-токены, ничего не выпускать}';

    protected $description = 'Выпустить (или отозвать) Bearer-токен ИИ-агента с ability agent:read';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));

        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error(sprintf('Пользователь с e-mail %s не найден.', $email));

            return self::FAILURE;
        }

        if (! StaffRole::isStaff($user)) {
            $this->error(sprintf(
                'Пользователь %s не имеет роли admin/manager — агентские токены выпускаются только сотрудникам.',
                $email,
            ));

            return self::FAILURE;
        }

        if ($this->option('list')) {
            $this->showTokens($user);

            return self::SUCCESS;
        }

        if ($this->option('revoke-all')) {
            $revoked = $this->revokeAgentTokens($user);
            $this->info(sprintf('Отозвано прежних agent-токенов: %d.', $revoked));
        }

        $name = trim((string) $this->option('name')) ?: 'AI agent';
        $token = $user->createToken($name, [self::ABILITY]);

        $this->info('Токен выпущен. Скопируйте его СЕЙЧАС — позже он не показывается:');
        $this->line('');
        $this->line($token->plainTextToken);
        $this->line('');
        $this->line(sprintf(
            'Подключение агента: URL https://ваш-домен/mcp, заголовок Authorization: Bearer <токен>.',
        ));

        return self::SUCCESS;
    }

    private function showTokens(User $user): void
    {
        $tokens = $this->agentTokens($user)
            ->get(['id', 'name', 'created_at', 'expires_at'])
            ->map(fn (object $row): array => [
                'id' => $row->id,
                'name' => (string) $row->name,
                'created_at' => (string) $row->created_at,
                'expires_at' => $row->expires_at !== null ? (string) $row->expires_at : 'бессрочно',
            ]);

        if ($tokens->isEmpty()) {
            $this->line('Активных agent-токенов нет.');

            return;
        }

        $this->table(['id', 'название', 'создан', 'истекает'], $tokens->all());
    }

    private function revokeAgentTokens(User $user): int
    {
        return $this->agentTokens($user)->delete();
    }

    /**
     * Активные agent-токены пользователя (ПАТ с ability `agent:read`).
     *
     * Фильтр по подстроке в `abilities` (JSON-массив, например
     * `["agent:read"]`) безопасен: не существует способности, в которую
     * `agent:read` входит как часть (`agent:reader` не предусмотрен
     * словарём abilities проекта).
     */
    private function agentTokens(User $user): \Illuminate\Database\Eloquent\Builder
    {
        return DB::table('personal_access_tokens')
            ->where('tokenable_type', $user::class)
            ->where('tokenable_id', $user->id)
            ->where('abilities', 'like', '%'.self::ABILITY.'%');
    }
}
