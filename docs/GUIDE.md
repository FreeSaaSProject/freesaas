# FreeSaaS — руководство по системе

Документ для людей: что это за проект, как устроен код, как работать и как добавлять модули.

Для AI-агентов см. [`AGENTS.md`](../AGENTS.md) в корне репозитория. Аудит agent-native готовности и нормативные требования к платформе/модулям: [`agent-native-audit.md`](agent-native-audit.md). Операционные runbooks: [`agents/`](agents/).

---

## Что это

**FreeSaaS** — модульный PHP CRM / операторский интерфейс на отключаемых product-модулях.

Ключевые правила:

- Runtime работает с таблицами **`base_*`**.
- Функциональность делится на **модули** в `modules/<id>/` с манифестом `module.json`.

Владение таблицами: [`TABLE_MAP.md`](TABLE_MAP.md).

---

## Быстрый старт

1. Скопировать `cfg/config.example.ini` → `cfg/config.ini` (для local обычно уже есть).
2. При необходимости скопировать `modules/<id>/config.example.ini` → `cfg/modules/<id>.ini` (audio/calendar/ledger и т.п.; секреты не в git).
3. Направить docroot vhost на корень этого проекта.
4. **Автономный bootstrap** (миграции + первый суперadmin + `agent_token`):

```bash
# CLI
php bin/freesaas.php bootstrap --login=admin --password='…' --agent-token='…' --format=json

# Shared hosting (без SSH) — POST /agent/bootstrap, см. agents/install.md
```

Либо по шагам: `php bin/freesaas.php migrate run` / UI `/db`, затем создать пользователя вручную.

5. Войти на `/login`. Дальнейшие ops: CLI или `/agent/*` ([`agents/remote-api.md`](agents/remote-api.md)).

Включение/выключение модулей: UI `/modules` → **Activate**, CLI `module activate` / `module enable`, или секция `[modules]` в `cfg/config.ini` (`=1` явно; absent = выключен).

---

## Структура репозитория

```
freesaas.likespa.hm/
├── index.php              # Front controller → Router::dispatch()
├── .htaccess              # Rewrite на index.php; блок app/cfg/bin/docs
├── cfg/
│   ├── config.ini         # Платформа: connect/app/salts/modules (не в git)
│   ├── config.example.ini
│   └── modules/           # Live ini модулей (*.ini gitignored); примеры в modules/<id>/config.example.ini
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
│   ├── DataMigrator.php   # Опциональные одноразовые data-миграторы из манифестов
│   ├── StageTiming.php    # Тайминги этапов запроса
│   ├── ListQuery.php      # Пагинация и сортировка списков
│   ├── BulkActions.php    # Выделение строк и групповые операции
│   ├── helpers.php        # e(), t(), redirect(), safe_html(), …
│   └── lang/              # Общие строки UI (ru/en)
├── bin/
│   ├── freesaas.php       # Agent/ops CLI (system, module, migrate, update, locale, health)
│   ├── build-release-artifacts.sh  # Release zips + manifest for UpdateEngine
│   ├── smoke.php          # Minimal CI smoke
│   ├── schema-migrate.php
│   ├── data-migrate.php
│   ├── check-i18n.php
│   ├── health.php
│   └── module-validate.php
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
| **Config** | Main `cfg/config.ini` (platform) + merge `cfg/modules/*.ini`. `moduleEnabled` / `setModuleEnabled`. Product `Config::set('calendar',…)` пишет в `cfg/modules/calendar.ini`. `core` всегда включён. Модуль без ключа в `[modules]` = **выключен**. |
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
| **DataMigrator** | Файлы `migrators/*.php` по списку в `module.json` (одноразовый replace/seed; не часть обычного runtime). |

Хелперы (`helpers.php`): экранирование (`e`, `e_nl`, `safe_html`, `comment_html`), перевод UI (`t`, `te`, `tj`), `redirect`, `request_path`, `base_path`, UI-фичи ленты (`deadline_chip`, `status_pill`, `status_comment_parts`).

### Локализация UI

- Строки интерфейса — через `t('module.key')` (в HTML-атрибутах/тексте — `te()`, в `confirm()` — `tj()`).
- Каталоги: `app/lang/{locale}.php` и `modules/<id>/lang/{locale}.php` (плоский массив ключ → строка).
- Язык: session → cookie `freesaas.locale` → `base_users.locale` (при логине) → `app.locale` в ini (дефолт `ru`).
- Переключатель в topbar / login → `GET /locale?lang=en&return=…`.
- Контент пользователей (новости, комментарии, клиенты) и `NAME` статусов из БД **не** переводятся.
- Новая локаль: добавить `app/lang/{code}.php` и `modules/*/lang/{code}.php`; список supported собирается с диска (опционально `app.locales` в ini). Проверка: `php bin/freesaas.php locale validate --locale={code}`.

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
| `description` | i18n-ключ `{id}.module.description` (короткий blurb ru+en); не English prose в JSON |
| `catalog` | Каталог UI/агентов: `{ categories[], maturity, tags[], screenshots[] }` — many-to-many categories (allowlist `ModuleCatalog`); `maturity`: `alpha`\|`beta`\|`release` (сейчас чаще `beta`); скрины под `assets/catalog/` |
| `requires` / `optionalRequires` | Зависимости от других модулей |
| `provides` | Декларация возможностей (каталог / агенты; runtime пока не потребляет) |
| `permissions` | `{ id, label }` — `label` = i18n-ключ (`myid.perm.view`), не готовый текст |
| `routes.pages` | Список путей (фактическая регистрация — в `bootstrap.php`) |
| `menu` | Пункты бокового меню: `group`, `num`, `path`, `title` — `group`/`title` тоже i18n-ключи |
| `tables` | Эксклюзивно свои `base_*` (uninstall dump→DROP). Не перечислять чужие таблицы, которые модуль только использует/`ALTER` |
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
| **rates** / **procents** / **payments** | Зарплата: ставки, начисления, взаиморасчёты |
| **vacations** | Отпуска (меню Команда, рядом со сменами) |
| **ledger** | Счета, типы движений, ДПС, переводы, сверки |
| **funds** | Фонды и фондовые движения (CAPUSE/REFUND); требует ledger |
| **finance** | Финансовая отчётность (ОДДС/ОПиУ/баланс без инфляции и амортизации) |
| **liquid_assets** | Ликвидные активы + T-Invest (снимки портфеля); требует ledger; soft funds; отключаемый |

---

## Паттерн page → view → repository

Типичный экран (пример: контракты):

1. **`bootstrap.php`** регистрирует маршрут → `require pages/list.php`.
2. **`pages/list.php`** читает GET/POST, дергает `*Repository`, вызывает `View::render('list', $data, 'layout', 'contracts')`.
3. **`views/list.php`** — HTML (переменные из `extract`).
4. **`lib/ContractsRepository.php`** — SQL только к `base_*`.

Хлебные крошки (системный chrome layout):

1. В **page** собрать полный путь от модуля вглубь и обернуть `Breadcrumbs::withHome(...)` — первым всегда Home (`/`). Корень списка: `['label' => t('myid.menu.list'), 'current' => true]`.
2. Передать в `View::render(..., ['breadcrumbs' => $trail, 'breadcrumbs_aria' => t('…')])`. Layout рисует их **сверху** `<main>` на каждой странице.
3. Во **view** не вызывать `Breadcrumbs::render` — иначе будет дубль. Если `breadcrumbs` не передать, layout сам соберёт `Home / пункт меню / title`.
4. **Авто-ссылка корня списка:** на точном path пункта меню, если в query есть фильтры/пейджер/сортировка, листовая крошка сама становится ссылкой на чистый URL списка (модулю ничего делать не нужно). Дефолтный экран лучше держать на URL без query.
5. Partial: `modules/core/views/_breadcrumbs.php`.
6. **Возврат с фильтрами (`NavContext`) — автоматически**, модулю ничего писать не нужно:
   - `View::render` прогоняет HTML через `NavContext::enrichContent`: исходящие ссылки на другой path получают `ret=` (текущий URL с фильтрами); «голые» cancel/up на path из `ret` становятся полным `ret`; в формы добавляется hidden `ret`.
   - `redirect()` сам вызывает `NavContext::redirectUrl` (тот же path, что у `ret` → возврат на фильтры; иначе `ret` сохраняется).
   - Крошки / `[Возврат]` в layout: `up()` и кнопка возврата.
   - `core.js` дублирует логику для динамических ссылок и submit.
   - Opt-out: `data-nav-no-ret` на `<a>` / `<form>`. Явные хелперы (`withReturn`, `hiddenField`, …) остаются для редких edge cases (например `window.location =` в своём JS).

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
| `core.layout.gate` | layout | Перехват chrome: `blocked` + `html`, слоты `chrome` (rail) и `floating` (FAB), `page_context_json`, доп. `js_i18n` (без product-кода в core) |
| `core.news_item.handle_post` | news_item | POST-actions (like, comment…) |
| `core.news_item.section` | news_item | HTML-секция под карточкой |
| `core.news_item.meta` | news_item | Мета/счётчики в шапке |
| `comments.prepare_add` | comments | Обогащение текста/extra перед insert |
| `comments.form.offer_fields` | comments form | Поля оффера (contracts) |
| `comments.form.ticket_controls` | comments form | Статус/куратор/ОЛ (core) |
| `comments.render_actions` | comments view | Кнопки на комментарии (contracts) |
| `core.auth.after_login` | Auth | После успешного входа |
| `core.auth.before_logout` | Auth | Перед очисткой сессии (`user_id`) |
| `core.bulk.check_item` | BulkActions | Перед id в групповой операции: `blocked` / `reason` |
| `core.settings.sections` | settings | HTML-блоки модулей на `/settings` (платформенные; product-настройки — на `/<module>/settings`) |
| `core.settings.save` | settings | Сохранение полей, добавленных модулями на `/settings` |
| `core.menu.items` | layout | Мутация списка пунктов rail после сборки (`items[]`); например persona-имя ассистента |
| `audio.settings.sections` | audio settings | HTML-блоки на `/audio/settings` (напр. `audio_reports`) |
| `audio.settings.save` | audio settings | Сохранение секций на `/audio/settings` |

Слушатели регистрируются в `bootstrap.php` модуля. Порядок = порядок boot модулей (сортировка по id).

---

## Групповые операции (BulkActions)

Платформенный сервис [`app/BulkActions.php`](../app/BulkActions.php) — чекбоксы в list-таблицах и действия над выбранными id.

### Регистрация (в `bootstrap.php` модуля)

```php
BulkActions::register('units', 'delete', [
    'label' => 'core.bulk.delete',          // i18n-ключ
    'permission' => 'units.delete',         // отдельное право удаления (не .edit)
    'confirm' => 'core.bulk.confirm_delete',
    'fields' => [ /* опционально: select/input при выборе действия */ ],
    'run' => static function (array $ids, array $params): array {
        return ['done' => UnitsRepository::deleteByIds($ids), 'skipped' => []];
    },
]);
```

`list_id` совпадает с `ListQuery` (`units`, `procedures`, `procedure_types`, …). Действие видно только при прохождении `permission` / `capability`.

### Удаление на экране редактирования (`EntityDelete`)

[`app/EntityDelete.php`](../app/EntityDelete.php) — красная кнопка danger-zone на edit, тот же `list_id`/`delete` runner и `core.bulk.check_item`, что у списка.

```php
// page (до save):
if (!$isNew && EntityDelete::tryHandle('units', $id, '/units', [
    'stay_path' => '/units/' . $id . '/edit',
])) {
    return;
}
// view (после save-form):
<?= EntityDelete::form('units', $id) ?>
```

JSON/agent delete дополнительно блокируется флагом `app.agent_allow_delete` (UI: **/settings → Agent**; default off). Staff UI с правом `*.delete` не зависит от флага.

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

Общие строки UI: `core.bulk.*` в `app/lang/`. Select-all — только на текущей странице. Клик по ячейке галки (не только по input) переключает строку; Shift+клик выделяет или снимает диапазон от предыдущей галки; выбранные строки подсвечиваются (`is-bulk-selected`).

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
- CLI: `php bin/freesaas.php migrate run` или UI `/db` / при connect модуля.

### Data (`migrators/<id>.php`)

Опциональные одноразовые импорты/замены данных, объявленные в `module.json` → `migrators`. В обычном runtime (pages, lib, views, EventBus) — только `base_*`.

---

## Auth и права

1. **Маршрут**: `Auth::can('module.perm')` — из личного blob; суперадмин всегда проходит.
2. **Меню**: `Auth::canSeeInMenu('module.perm')` — те же галочки в профиле, **включая суперадмина** (снятая галочка скрывает пункт в rail; URL при необходимости всё ещё открывается).
3. **Роль**: колонки-флаги в `base_admin_statuses` → `Auth::capability('FLAG')`.
4. Футер layout: если namespaced permissions не сохранены — предупреждение настроить профиль; если суперадмин открыл экран с выключенной личной галочкой — напоминание про bypass.

Пароль: новые записи — `password_hash` (argon2id/bcrypt); legacy `sha1(md5(plain . salts.site))` проверяется и при успешном входе перехешируется. `salts.site` не ротировать. Блокировка: `retries` + `locked_until` (5/15 мин). Ссылка входа без бота: `php bin/freesaas.php user login-link <login>` или кнопка в карточке сотрудника.

Сессии: файлы в `var/sessions/<host>/` (не `/tmp`). `Auth::check()` перечитывает `enabled` / `auth_epoch` / права (троттлинг ~60 с; всегда на POST и capability-маршрутах). Смена пароля/роли/enabled бампает `auth_epoch` (чужие сессии умирают). Idle: `session_idle_minutes` (по умолчанию 120) только без «Запомнить меня»; с галочкой remember действует `session_remember_days` (скользящая cookie, в т.ч. после `session_regenerate_id`). Не ставьте один `session_cookie_domain=.likespa.ru` на два инстанса (mf / freesaas) — cookie-имя совпадёт и логины будут выбивать друг друга; задайте разный `session_name`. CSRF-токен один на сессию (ротация при логине); периодический `session_regenerate_id` CSRF не меняет — несколько вкладок с одной формой не ломаются. «Выйти везде»: UI в карточке или `php bin/freesaas.php user logout-all <login>`.

---

## UI / фронт

- Layout: `modules/core/views/layout.php` (и `layout_auth.php` для логина).
- Ядро: `assets/css/core.css`, `assets/js/core.js` (всегда в layout).
- Модули: `modules/<id>/assets/{css,js}/` — регистрация в `bootstrap.php` через `Assets::register('myid', ['css' => ['x.css'], 'js' => ['y.js']])`. `View::render(..., 'myid')` сам ставит модуль в очередь; эмбеды чужого UI — `Assets::need('other')`. Layout выводит теги через `Assets::cssTags()` / `Assets::jsTags()`.
- Статика модулей отдаётся напрямую (исключение в `.htaccess` для `/modules/*/assets/*.{css,js,…}`); остальной `/modules` идёт в front controller.
- Меню: пункты из `module.json` → `ModuleManager::menuItems()` / `menuGrouped()`. Порядок **групп** rail — `ModuleManager::registerMenuGroup('myid.menu.group', N)` в bootstrap владельца группы (не в `app/ModuleManager.php`). Shared `core.menu.group.*` — только `modules/core/bootstrap.php`. Неизвестный ключ → вес `1000`.
- Редактирование сотрудника: core emit `core.user_edit.sections` / `save` / `profile`; product-поля только из bootstrap модулей.
- Список сотрудников: `core.users.list` — `items` + `extra_columns` (`[{key,label}]`); модули обогащают строки (напр. `bots` → chat ids из `base_bot_links`).
- Клиенты: `clients.edit.sections` / `save` / `clients.view.profile` / `clients.list` — тот же паттерн, без колонок messenger в `base_clients`.
- Флаги ролей (`base_admin_statuses`): `StatusesRepository::registerFieldGroup` в bootstrap; в core только группы `when=always`.
- Конфиг модулей: `modules/<id>/config.example.ini` → `cfg/modules/<id>.ini`; не добавлять product-секции в `config.example.ini`.

---

## Как добавить новый модуль

Краткий чеклист:

1. Создать каталог `modules/myfeature/`.
2. Написать `module.json` (`id`, `version`, `requires: { "core": ">=0.3.0" }`, permissions, menu, tables, hooks). Поля `name`, `menu[].title|group`, `permissions[].label` — **ключи перевода**, не строки на одном языке.
3. Добавить `lang/ru.php` и `lang/en.php` со всеми UI-строками модуля; во views/pages — только `t()` / `te()` / `tj()`.
4. Добавить `bootstrap.php`: `require` репозиториев, при необходимости `Assets::register` / `registerMenuGroup` / `StatusesRepository::registerFieldGroup` / `core.user_edit.*`, `ModuleManager::registerRoute(...)`, `EventBus::on(...)`.
5. При необходимости: `pages/`, `views/`, `lib/`, `migrations/`, `assets/{css,js}/`, `config.example.ini`.
6. Активировать через UI `/modules/{id}/activate` или `module activate` (миграции при enable). Скопировать config.example → `cfg/modules/` при наличии. Заполнить `description` + `catalog` + `{id}.module.summary` + `setup.steps` (ru/en).
7. Runtime и новые таблицы — только `base_*`; владение таблицами — в `module.json` → `tables`.
8. Если нужно встроиться в существующий экран — подписаться на hook, а не править чужой page без необходимости.

Минимальный `bootstrap.php` с одним экраном:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/MyRepository.php';

ModuleManager::registerMenuGroup('myfeature.menu.group', 45);

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

Канонический протокол для агентов и ops — [`bin/freesaas.php`](../bin/freesaas.php) (текст или `--format=json`). Коды выхода: `0` ok, `1` fail, `2` usage.

| Команда | Действие |
|---------|----------|
| `php bin/freesaas.php system status` | PHP/DB/config, локаль, счётчики модулей |
| `php bin/freesaas.php bootstrap --login=… --password=…` | Первый admin + migrate + `agent_token` (см. [`agents/install.md`](agents/install.md)) |
| `php bin/freesaas.php module list [--q=] [--category=] [--tag=] [--status=] [--maturity=]` | Каталог модулей (bilocale name/description, filters); `available[]` — ещё не на диске |
| `php bin/freesaas.php module install <id>` | Скачать модуль с репо обновлений (не на диске); дальше `module activate` |
| `php bin/freesaas.php module activate <id> …` | Snapshot / apply: enable, grant, setup.steps (UI twin `/modules/{id}/activate`) |
| `php bin/freesaas.php module pin-effective` | Апгрейд: absent → `=1`, затем `app.modules_absent_off=1` (новые absent остаются выкл.) |
| `php bin/freesaas.php module recover-from-example` | Опционально: включить ids с `=1` из `config.example.ini`, если они сейчас выкл. |
| `php bin/freesaas.php module uninstall <id> --confirm` | Бэкап owned-таблиц → DROP → ledger clear → disable (`leave_tables` отказ) |
| `php bin/freesaas.php module enable <id> [--accept-risk]` | Включить; для alpha/beta нужен `--accept-risk` |
| `php bin/freesaas.php module inspect <id>` | Манифест + routes + `agent_ops` / hooks / bulk / soft_deps / capabilities |
| `php bin/freesaas.php module enable\|disable <id>` | Вкл/выкл (при enable — schema migrate) |
| `php bin/freesaas.php module validate [id]` | Структура + soft-dep honesty (`isEnabled` / foreign pages) |
| `php bin/freesaas.php migrate status\|run [--module=id]` | Статус и прогон schema-миграций |
| `php bin/freesaas.php locale list\|validate [--locale=xx]` | Поддерживаемые локали (discovery `app/lang`) и паритет каталогов |
| `php bin/freesaas.php health [--module=id]` | Liveness/readiness/health checks |
| `php bin/freesaas.php i18n check` | То же, что locale validate + used-key scan |
| `php bin/freesaas.php update status\|check\|apply [--target=…]` | Самообновление ядра/модулей ([`agents/update.md`](agents/update.md)) |
| `php bin/smoke.php` | Быстрый CI: system + locale + validate + health |
| `php bin/schema-migrate.php` | Совместимость: DDL всех включённых |
| `php bin/data-migrate.php` | Data-migrators из манифестов |

Тонкие обёртки: `bin/health.php`, `bin/module-validate.php`, `bin/check-i18n.php`.

HTTP (тот же `Health::run` / `AgentOps`):

- `GET /health` — публичный минимальный JSON (`status`, `liveness`, `readiness`, `summary`)
- `GET /health/detail` — полный список checks (нужен `core.modules`); `?module=id`
- **`/agent/*`** — полный ops-API для агентов на shared hosting (токен `app.agent_token`, заголовок `X-FreeSaaS-Agent-Token`; всегда обязателен). Первый HTTP-подъём: pre-set токена **или** `var/setup.key`, затем `POST /agent/bootstrap` — анонимного virgin bootstrap нет ([`agents/install.md`](agents/install.md)). Каталог: `GET /agent`. Самообновление: [`agents/update.md`](agents/update.md). См. [`agents/remote-api.md`](agents/remote-api.md).

Health: платформенные checks (DB, ledger, config, blocked modules, agent_token) + авто-проверка `tables[]` из `module.json` + модульные `Health::register` (например `asr.worker_token`). Модули регистрируют checks в `bootstrap.php`.

Локали: список из файлов `app/lang/*.php`; опционально `app.locales` в ini. Uninstall по-прежнему = disable (таблицы остаются) — см. [`agent-native-audit.md`](agent-native-audit.md). Agent runbooks: [`agents/`](agents/).

---

## Что смотреть дальше по коду

- Регистрация маршрутов core: `modules/core/bootstrap.php`
- Расширение карточки новости: `modules/comments/bootstrap.php` + `modules/core/pages/news_item.php`
- Контракты как пример product-модуля: `modules/contracts/`
- Каталог модулей: `modules/core/pages/modules.php` + detail `/modules/{id}`; DTO `app/ModuleCatalog.php`
