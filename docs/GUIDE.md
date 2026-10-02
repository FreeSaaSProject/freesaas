# FreeSaaS — руководство по системе

Документ для людей: что это за проект, как устроен код, как работать и как добавлять модули.

Для AI-агентов см. [`AGENTS.md`](../AGENTS.md) в корне репозитория.

---

## Что это

**FreeSaaS** — модульный PHP CRM/операторский интерфейс (инстанс likespa). Новый Core живёт рядом с legacy `oper.likespa.hm` и постепенно заменяет его экраны.

Ключевые правила:

- Код FreeSaaS **не читает и не пишет** таблицы `phpshop_*`.
- Новые таблицы — префикс `base_*`.
- Функциональность делится на **модули** в `modules/<id>/` с манифестом `module.json`.

Карта legacy → FreeSaaS таблиц: [`LEGACY_TABLE_MAP.md`](LEGACY_TABLE_MAP.md).

---

## Быстрый старт

1. Скопировать `cfg/config.example.ini` → `cfg/config.ini` (для local обычно уже есть).
2. Направить docroot vhost на корень этого проекта.
3. Прогнать миграции:

```bash
php bin/schema-migrate.php
php bin/data-migrate.php
```

Либо открыть `/migrate` после деплоя (для первого входа данные пользователей часто нужны из CLI).

4. Войти логином сотрудника из oper (пароли с тем же site salt).

Включение/выключение модулей: UI `/modules` или секция `[modules]` в `cfg/config.ini`.

---

## Структура репозитория

```
freesaas.likespa.hm/
├── index.php              # Front controller → Router::dispatch()
├── .htaccess              # Rewrite на index.php; блок app/cfg/bin/docs
├── cfg/
│   ├── config.ini         # БД, соли, флаги модулей (не в git-секретах)
│   └── config.example.ini
├── app/                   # Ядро платформы (не модуль)
│   ├── bootstrap.php      # Загрузка сервисов + ModuleManager
│   ├── Config.php         # INI-конфиг, enable/disable модулей
│   ├── Database.php       # PDO, tableExists / columnExists
│   ├── Auth.php           # Сессия, права, capability по статусу
│   ├── I18n.php           # Локализация UI (t / каталоги / locale)
│   ├── Router.php         # Маршруты из ModuleManager
│   ├── View.php           # Рендер views + layout
│   ├── EventBus.php       # Хуки между модулями
│   ├── ModuleManager.php  # Discovery, deps, меню, registry
│   ├── SchemaMigrator.php # DDL-миграции modules/*/migrations
│   ├── DataMigrator.php   # Копирование данных из legacy
│   ├── StageTiming.php    # Тайминги этапов запроса
│   ├── ListQuery.php      # Пагинация и сортировка списков
│   ├── BulkActions.php    # Выделение строк и групповые операции
│   ├── helpers.php        # e(), t(), redirect(), safe_html(), …
│   └── lang/              # Общие строки UI (ru/en)
├── bin/
│   ├── schema-migrate.php
│   └── data-migrate.php
├── modules/
│   ├── core/              # Платформа: auth, users, news, settings…
│   ├── comments/          # Комментарии к новостям/заявкам (без своих URL)
│   ├── contracts/         # Контракты / офферы
│   └── clients/           # Клиентская база + статусы клиентов
├── assets/css|js          # Ядро (core.css / core.js)
├── modules/<id>/assets/   # CSS/JS модуля (page-scoped via Assets)
├── docs/                  # Документация
└── log/                   # Логи (если используются)
```

---

## Как обрабатывается запрос

```
Браузер → .htaccess → index.php
  → app/bootstrap.php
       Config::load
       Auth::startSession
       I18n::bootFromRequest()
       ModuleManager::discover()      # читает modules/*/module.json
       ModuleManager::bootEnabled()   # require bootstrap.php включённых
       syncRegistry() в base_modules  # если таблица есть
  → Router::dispatch()
       path + method → маршрут
       auth / permission
       handler() → pages/*.php → View::render()
```

Важные детали:

- Путь `/modules` специально прокидывается в `index.php` (иначе Apache отдаёт каталог `modules/` и 403).
- Прямой доступ к `app/`, `cfg/`, `bin/`, `docs/` запрещён.
- Маршруты с `{id}` матчятся regex; при нескольких совпадениях берётся более длинный паттерн.

---

## Ядро (`app/`)

| Класс | Назначение |
|-------|------------|
| **Config** | `cfg/config.ini`. `moduleEnabled` / `setModuleEnabled`. `core` всегда включён. Модуль без ключа в `[modules]` считается включённым. |
| **Database** | Один PDO (utf8mb4). Кэш `tableExists` / `columnExists`. |
| **Auth** | Сессия `freesaas`. Права из `base_users.status` (unserialize permissions) + флаги роли из `base_admin_statuses` (`capability`). Superadmin обходит проверки. |
| **ModuleManager** | Discovery, зависимости semver, boot, маршруты, меню, sync в `base_modules`, UI-статус модуля. |
| **Router** | Диспетчер. `public`, `permission`, `methods`, `handler`. |
| **View** | `modules/<module>/views/<name>.php` + layout из `modules/core/views/`. |
| **ListQuery** | Общий листатель (`page`) и сортировка колонок (`sort`/`dir`) для list/CRUD. Allow-list SQL, HTML pager + `sortTh`. |
| **BulkActions** | Выделение строк в таблицах и групповые операции. Регистрация действий в `bootstrap` модуля; UI-хелперы; проверки зависимостей через `core.bulk.check_item`. |
| **I18n** | UI-локали `ru`/`en`: каталоги `app/lang/` + `modules/*/lang/`, `t()`/`te()`/`tj()`, cookie/session/`base_users.locale`, переключатель `/locale`. |
| **EventBus** | `on` / `emit`: цепочка слушателей мутирует payload и возвращает его. |
| **SchemaMigrator** | Файлы `migrations/*.php` по порядку имени; ledger `base_schema_migrations`. |
| **DataMigrator** | Файлы `migrators/*.php` по списку в `module.json`; обычно `replace` из `phpshop_*` → `base_*` (только в migrators). |

Хелперы (`helpers.php`): экранирование (`e`, `e_nl`, `safe_html`, `comment_html`), перевод UI (`t`, `te`, `tj`), `redirect`, `request_path`, `base_path`, UI-фичи ленты (`deadline_chip`, `status_pill`, `status_comment_parts`).

### Локализация UI

- Строки интерфейса — через `t('module.key')` (в HTML-атрибутах/тексте — `te()`, в `confirm()` — `tj()`).
- Каталоги: `app/lang/{locale}.php` и `modules/<id>/lang/{locale}.php` (плоский массив ключ → строка).
- Язык: session → cookie `freesaas.locale` → `base_users.locale` (при логине) → `app.locale` в ini (дефолт `ru`).
- Переключатель в topbar / login → `GET /locale?lang=en&return=…`.
- Контент пользователей (новости, комментарии, клиенты) и `NAME` статусов из БД **не** переводятся.
- Новая локаль: файлы каталогов + добавить код в `I18n::supported()`.

---

## Модули

### Анатомия модуля

```
modules/<id>/
├── module.json       # манифест (обязателен)
├── bootstrap.php     # маршруты, EventBus::on, require lib/
├── lang/             # ru.php + en.php — UI chrome (обязательно)
├── pages/            # обработчики HTTP (по одному файлу на экран/action)
├── views/            # шаблоны для View::render(..., module: <id>)
├── lib/              # *Repository.php и прочая логика
├── migrations/       # schema: return function(PDO $pdo): void
└── migrators/        # data: return function(PDO $pdo, array $spec)
```

Не у всех модулей есть `pages/` (например `comments` только расширяет чужие экраны через хуки). Каталоги `lang/ru.php` и `lang/en.php` обязательны, если у модуля есть UI-строки (включая меню, permissions, виджеты, flash).

### `module.json` — поля

| Поле | Смысл |
|------|--------|
| `id`, `name`, `version` | Идентификация; version для deps (`>=0.3.0`, `^1.0`, `~1.2`) |
| `type` | `platform` (core) или `product` |
| `requires` / `optionalRequires` | Зависимости от других модулей |
| `provides` | Декларация возможностей (документация / будущее) |
| `permissions` | `{ id, label }` — `label` = i18n-ключ (`myid.perm.view`), не готовый текст |
| `routes.pages` | Список путей (фактическая регистрация — в `bootstrap.php`) |
| `menu` | Пункты бокового меню: `group`, `num`, `path`, `title` — `group`/`title` тоже i18n-ключи |
| `tables` | Какие `base_*` модуль считает «своими» |
| `migrators` | Спеки data-миграторов |
| `hooks.subscribe` / `hooks.publish` | Документация событий EventBus |

### Включение и зависимости

- Включение через `/modules` или `cfg/config.ini` → `[modules] id=1|0`.
- При **включении** сначала гоняются schema-миграции модуля, потом пишется флаг.
- Нельзя выключить модуль, от которого зависят другие включённые.
- `core` нельзя выключить.
- Если hard-dep не выполнен, модуль не бутится (`_block_reason` в лог/UI).

### Текущие модули

| Модуль | Роль |
|--------|------|
| **core** | Логин, home, users, statuses, news/лента/заявки, settings, modules UI, migrate |
| **comments** | Дерево комментариев на карточке новости; likes; prepare_add / form hooks |
| **contracts** | Список контрактов, офферы в форме комментария, actions на комментариях, виджеты/нотисы |
| **clients** | Клиентская база (`base_clients`) и статусы клиентов (`base_client_statuses`) |
| **rates** / **procents** / **payments** / **vacations** | Бухгалтерия: ставки, начисления, взаиморасчёты, отпуска |
| **ledger** | Счета, типы движений, ДПС, переводы, сверки |
| **funds** | Фонды и фондовые движения (CAPUSE/REFUND); требует ledger |
| **finance** | Финансовая отчётность (ОДДС/ОПиУ/баланс без инфляции и амортизации) |

---

## Паттерн page → view → repository

Типичный экран (пример: контракты):

1. **`bootstrap.php`** регистрирует маршрут → `require pages/list.php`.
2. **`pages/list.php`** читает GET/POST, дергает `*Repository`, вызывает `View::render('list', $data, 'layout', 'contracts')`.
3. **`views/list.php`** — HTML (переменные из `extract`).
4. **`lib/ContractsRepository.php`** — SQL только к `base_*`.

Хлебные крошки (системный chrome layout, стиль oper):

1. В **page** собрать полный путь от модуля вглубь и обернуть `Breadcrumbs::withHome(...)` — первым всегда Home (`/`).
2. Передать в `View::render(..., ['breadcrumbs' => $trail, 'breadcrumbs_aria' => t('…')])`. Layout рисует их **сверху** `<main>` на каждой странице.
3. Во **view** не вызывать `Breadcrumbs::render` — иначе будет дубль. Если `breadcrumbs` не передать, layout сам соберёт `Home / пункт меню / title`.
4. Partial: `modules/core/views/_breadcrumbs.php`. Предки — ссылки, текущий уровень — текст.

Права:

- `permission` на маршруте → `Auth::can('module.perm')`.
- Бизнес-флаги роли → `Auth::capability('CONTROL_ACCESS')` и т.п. из `base_admin_statuses`.

Параметры URL: `Router::param('id')` для `{id}` в паттерне.

---

## EventBus (расширение без правок чужого модуля)

Паттерн: один модуль **emit**, другие **on** и возвращают изменённый payload.

Примеры событий:

| Событие | Кто emit | Зачем |
|---------|----------|--------|
| `core.dashboard.catalog` | home / dashboard | Каталог виджетов рабочего стола |
| `core.dashboard.widgets` | home | Виджеты на главной |
| `core.notices.collect` | layout | Колокольчик / notices |
| `core.news_item.handle_post` | news_item | POST-actions (like, comment…) |
| `core.news_item.section` | news_item | HTML-секция под карточкой |
| `core.news_item.meta` | news_item | Мета/счётчики в шапке |
| `comments.prepare_add` | comments | Обогащение текста/extra перед insert |
| `comments.form.offer_fields` | comments form | Поля оффера (contracts) |
| `comments.form.ticket_controls` | comments form | Статус/куратор/ОЛ (core) |
| `comments.render_actions` | comments view | Кнопки на комментарии (contracts) |
| `core.auth.after_login` | Auth | После успешного входа |
| `core.bulk.check_item` | BulkActions | Перед id в групповой операции: `blocked` / `reason` |
| `core.settings.sections` | settings | HTML-блоки модулей на `/settings` |
| `core.settings.save` | settings | Сохранение полей, добавленных модулями |

Слушатели регистрируются в `bootstrap.php` модуля. Порядок = порядок boot модулей (сортировка по id).

---

## Групповые операции (BulkActions)

Платформенный сервис [`app/BulkActions.php`](../app/BulkActions.php) — чекбоксы в list-таблицах и действия над выбранными id.

### Регистрация (в `bootstrap.php` модуля)

```php
BulkActions::register('units', 'delete', [
    'label' => 'core.bulk.delete',          // i18n-ключ
    'permission' => 'units.edit',           // или 'capability' => 'SUPERADMIN_ACCESS'
    'confirm' => 'core.bulk.confirm_delete',
    'fields' => [ /* опционально: select/input при выборе действия */ ],
    'run' => static function (array $ids, array $params): array {
        return ['done' => UnitsRepository::deleteByIds($ids), 'skipped' => []];
    },
]);
```

`list_id` совпадает с `ListQuery` (`units`, `procedures`, `procedure_types`, …). Действие видно только при прохождении `permission` / `capability`.

### UI в list-view

```php
$actionUrl = ListQuery::url($path, $filters, []);
<?= BulkActions::beginForm('units', $actionUrl) ?>
<?= BulkActions::bar('units') ?>
<table class="data">
  <thead><tr><?= BulkActions::th('units') ?>…</tr></thead>
  <tbody>
    <tr><?= BulkActions::td('units', (int)$row['id']) ?>…</tr>
  </tbody>
</table>
<?= BulkActions::endForm('units') ?>
```

В page: `BulkActions::tryHandle('units', '/units', $filters)` на POST, затем `$bulkFlash = BulkActions::consumeFlash()` и `BulkActions::flashHtml($bulkFlash)` во view. Маршрут списка должен принимать `POST`.

### Проверки зависимостей

Перед каждым id `BulkActions` эмитит `core.bulk.check_item` (`list_id`, `action`, `id`, `params`, `blocked`, `reason`). Другой модуль ставит `blocked=true` и текст причины — id попадает в skipped, удаление не вызывается. Пример: catalog блокирует delete единиц/поставщиков/типов процедур, если есть товары; procedures — delete клиента, если есть процедуры.

Общие строки UI: `core.bulk.*` в `app/lang/`. Select-all — только на текущей странице.

---

## Миграции

### Schema (`migrations/NNN_name.php`)

```php
<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS base_example (...)');
};
```

- Имя файла = id миграции; сортировка лексикографическая.
- Применяется один раз; запись в `base_schema_migrations`.
- CLI: `php bin/schema-migrate.php` или UI `/migrate` / при connect модуля.

### Data (`migrators/<id>.php`)

Только здесь допустимо читать `phpshop_*` для копирования в `base_*`. В обычном runtime-коде — нельзя.

---

## Auth и права

1. **Маршрут**: `Auth::can('module.perm')` — из личного blob; суперадмин всегда проходит.
2. **Меню**: `Auth::canSeeInMenu('module.perm')` — те же галочки в профиле, **включая суперадмина** (снятая галочка скрывает пункт в rail; URL при необходимости всё ещё открывается).
3. **Роль**: колонки-флаги в `base_admin_statuses` → `Auth::capability('FLAG')`.
4. Футер layout: если namespaced permissions не сохранены — предупреждение настроить профиль; если суперадмин открыл экран с выключенной личной галочкой — напоминание про bypass.

Пароль: `sha1(md5(plain . site_salt))` — совместимо с legacy.

---

## UI / фронт

- Layout: `modules/core/views/layout.php` (и `layout_auth.php` для логина).
- Ядро: `assets/css/core.css`, `assets/js/core.js` (всегда в layout).
- Модули: `modules/<id>/assets/{css,js}/` — регистрация в `bootstrap.php` через `Assets::register('myid', ['css' => ['x.css'], 'js' => ['y.js']])`. `View::render(..., 'myid')` сам ставит модуль в очередь; эмбеды чужого UI — `Assets::need('other')`. Layout выводит теги через `Assets::cssTags()` / `Assets::jsTags()`.
- Статика модулей отдаётся напрямую (исключение в `.htaccess` для `/modules/*/assets/*.{css,js,…}`); остальной `/modules` идёт в front controller.
- Меню собирается из `module.json` → `ModuleManager::menuGrouped()` / событие `core.menu.collect`.

---

## Как добавить новый модуль

Краткий чеклист:

1. Создать каталог `modules/myfeature/`.
2. Написать `module.json` (`id`, `version`, `requires: { "core": ">=0.3.0" }`, permissions, menu, tables, hooks). Поля `name`, `menu[].title|group`, `permissions[].label` — **ключи перевода**, не строки на одном языке.
3. Добавить `lang/ru.php` и `lang/en.php` со всеми UI-строками модуля; во views/pages — только `t()` / `te()` / `tj()`.
4. Добавить `bootstrap.php`: `require` репозиториев, при наличии стилей/скриптов — `Assets::register(...)`, `ModuleManager::registerRoute(...)`, `EventBus::on(...)`.
5. При необходимости: `pages/`, `views/`, `lib/`, `migrations/`, `assets/{css,js}/` (эмбеды чужого UI — `Assets::need('other')` в partial).
6. Включить в `cfg/config.ini` `[modules] myfeature=1` или через UI `/modules` (миграции прогонятся при connect).
7. Не ходить в `phpshop_*` из runtime; новые таблицы — `base_*`.
8. Если нужно встроиться в существующий экран — подписаться на hook, а не править чужой page без необходимости.

Минимальный `bootstrap.php` с одним экраном:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/MyRepository.php';

ModuleManager::registerRoute('/myfeature', [
    'module' => 'myfeature',
    'methods' => ['GET'],
    'permission' => 'myfeature.view',
    'handler' => static function (): void {
        require base_path('modules/myfeature/pages/index.php');
    },
]);
```

В page:

```php
View::render('index', ['title' => 'My feature', /* ... */], 'layout', 'myfeature');
```

---

## CLI

| Команда | Действие |
|---------|----------|
| `php bin/schema-migrate.php` | DDL всех включённых модулей |
| `php bin/data-migrate.php` | Data-migrators из манифестов |

---

## Что смотреть дальше по коду

- Регистрация маршрутов core: `modules/core/bootstrap.php`
- Расширение карточки новости: `modules/comments/bootstrap.php` + `modules/core/pages/news_item.php`
- Контракты как пример product-модуля: `modules/contracts/`
- UI модулей: `modules/core/pages/modules.php`
