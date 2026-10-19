<?php
declare(strict_types=1);

/**
 * Moderator-assistant prompts ([audio_llm] in cfg/modules/audio_llm.ini).
 * Multi-line values are stored as a single line with \n escapes (Config::set is raw).
 */
final class ReviewModerationConfig
{
    public const MAX_PENDING = 40;

    public static function systemPrompt(): string
    {
        $raw = self::decode((string)Config::get('audio_llm', 'moderation_system_prompt', ''));
        $raw = trim($raw);
        return $raw !== '' ? $raw : self::defaultSystemPrompt();
    }

    public static function userPrompt(): string
    {
        $raw = self::decode((string)Config::get('audio_llm', 'moderation_user_prompt', ''));
        $raw = trim($raw);
        return $raw !== '' ? $raw : self::defaultUserPrompt();
    }

    /** Raw stored values for settings textarea (empty = using defaults). */
    public static function storedSystemPrompt(): string
    {
        return self::decode((string)Config::get('audio_llm', 'moderation_system_prompt', ''));
    }

    public static function storedUserPrompt(): string
    {
        return self::decode((string)Config::get('audio_llm', 'moderation_user_prompt', ''));
    }

    public static function save(string $system, string $user): void
    {
        Config::set('audio_llm', 'moderation_system_prompt', self::encode(trim($system)));
        Config::set('audio_llm', 'moderation_user_prompt', self::encode(trim($user)));
    }

    /**
     * @param array{employee_name?:string,date?:string,transcripts?:string} $vars
     */
    public static function applyUserTemplate(string $template, array $vars): string
    {
        return str_replace(
            ['{employee_name}', '{date}', '{transcripts}'],
            [
                (string)($vars['employee_name'] ?? ''),
                (string)($vars['date'] ?? ''),
                (string)($vars['transcripts'] ?? ''),
            ],
            $template
        );
    }

    public static function defaultSystemPrompt(): string
    {
        return <<<'PROMPT'
Ты — краткий сканер транскриптов для руководителя.
Записи уже отобраны по словарям; словари — только повод глянуть, не доказательство.

Найди управленчески значимое. Считай значимым в том числе:

- сбои и «глюки» рабочих программ / приложений / кассы / CRM / записи клиентов,
  из‑за которых нельзя нормально обслужить гостя или оформить операцию;
- непонятные или спорные начисления зарплаты, процентов, бонусов, абонементов
  (сотрудник не понимает сумму, «должно начислиться само — не начислилось»,
  расхождение экрана и факта);
- поломки рабочих инструментов (телефон, сканер, браслет, терминал),
  если из‑за этого страдает обслуживание;
- жалобы клиентов без решения, ошибки расчётов, конфликты, влияющие на сервис,
  явное увольнение/невыход, системные оргпроблемы, полезные предложения.

Не ищи виноватых, не делай психологических выводов, не выдумывай.
Шутки, мат без контекста, слухи, быт, мелочи вроде температуры в кабинете — пропускай.
Разовое «неудобно в интерфейсе» без последствий — пропускай;
повторяющиеся сбои или неясность по деньгам — включай.
Объединяй одно событие из нескольких записей в один пункт.
Пустой результат — нормально, если действительно нет тем выше.

ФОРМАТ (строго):

Первая строка ровно одна из:
ЕСТЬ СОБЫТИЯ
или
ЗНАЧИМЫХ СОБЫТИЙ НЕТ

Если есть события — нумерованный список, по 1 строке на пункт:
N. Краткая суть (до ~12 слов). ID 123 18:37; ID 456 16:31

В конце пункта — только ID записи и время (начало или интервал из заголовка блока).
Без приоритета, без «почему важно», «достоверность», «действие», длинных цитат,
общего вывода и markdown (** , ---).
Максимум 5 пунктов. Ответ читается по диагонали за несколько секунд.
PROMPT;
    }

    public static function defaultUserPrompt(): string
    {
        return <<<'PROMPT'
Сотрудник: {employee_name}
Дата: {date}

Кратко: только значимые события, по одной строке на пункт.
Не пересказывай разговоры.
Обращай внимание на сбои рабочих программ и на разговоры про зарплату/начисления,
если это мешает работе или вызывает путаницу у сотрудников.

ТРАНСКРИПЦИИ:

{transcripts}
PROMPT;
    }

    private static function encode(string $value): string
    {
        return str_replace(["\r\n", "\r", "\n"], '\n', $value);
    }

    private static function decode(string $value): string
    {
        return str_replace('\n', "\n", $value);
    }
}
