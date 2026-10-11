# Modify a module

## Find the module

```bash
php bin/freesaas.php module list --format=json
php bin/freesaas.php module inspect <id> --format=json
```

Use `runtime_routes`, `hooks`, `tables`, `agent_ops`, `bulk_actions`, `capability_groups`, `soft_deps` from inspect. Prefer EventBus names from hooks over guessing. `module validate` flags undeclared `isEnabled` and foreign `pages/`/`views/` requires.

## Common change: add a field

1. Migration `modules/<id>/migrations/NNN_add_*.php` with `Database::columnExists` / `ALTER` guards.
2. Update repository + forms/views + `t()` keys in `lang/{ru,en}.php`.
3. Run:

```bash
php bin/freesaas.php migrate run --module=<id>
php bin/freesaas.php module validate <id>
php bin/freesaas.php locale validate
php bin/freesaas.php health --module=<id>
```

## Cross-module behavior

- Prefer `EventBus::emit` / `EventBus::on` in owning `bootstrap.php`.
- Declare real event names in `module.json` `hooks`.
- Soft deps: `optionalRequires` + `ModuleManager::isEnabled` (+ `class_exists` / `tableExists`).
- Do **not** add new `require` of another module’s `pages/`.

## After change

```bash
php bin/smoke.php --format=json
```
