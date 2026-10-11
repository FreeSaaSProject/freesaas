# Agent scenario benchmarks

Reproducible sequences an agent can run end-to-end. Each case lists preconditions, commands, and expected `doctor` / `smoke` / `health` fragments.

| Case | Goal |
|------|------|
| [vps-workweeks.md](vps-workweeks.md) | Clean host → FreeSaaS → workweeks (+ calendar) |
| [salon-stack.md](salon-stack.md) | Clients + catalog + orders + booking + sites |
| [bank-connect.md](bank-connect.md) | Ledger + banks enable path |

These are **ops benchmarks**, not PHPUnit. Record wall time and paste JSON when filing issues.
