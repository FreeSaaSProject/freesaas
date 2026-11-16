# EGISZ / REMD (`egisz`)

Alpha module: SEMD (CDA) from FreeSaaS medcards, SNILS / FRMR codes / MO OID, local ICD seed, outbox journal, and **N3.Health IEMK** adapter (demo SOAP + terminology + Eventlog).

**Primary test deploy:** `https://demomed.freesaas.ru`  
**N3 demo API** requires OpenVPN (`b2b-demo.n3health.ru`). Shared hosting cannot keep VPN — use the disableable edge plugin `workers/plugins/egisz` (Mac/VPS): FreeSaaS enqueues jobs when `worker_enabled=1`, plugin claims `/egisz/worker/*` with `X-Egisz-Token`, runs SOAP via PHP CLI after split-tunnel OpenVPN. Disable: `worker_enabled=0` and/or omit `egisz` from `workers/host.local.json` → `enabled_plugins`. Never commit `.ovpn` or tokens.

## UKEP — can we invent a signature for tests?

**No.** REMD verifies cryptographic CAdES against FRMR/FRMO. Fake base64 will only produce confusing validation errors.

What works **without** UKEP:

| Call | Need UKEP? |
|------|------------|
| AddPatient | No |
| Terminology / Eventlog | No |
| AddCase + MedRecord (unsigned XML) | Path test only — expect **reject** |

Real doctor + MO UKEP (CryptoPro plugin or DSS) is the next slice.

## Enable (on demomed)

```bash
php bin/freesaas.php module enable docs --accept-risk --format=json
php bin/freesaas.php module enable egisz --accept-risk --format=json
php bin/freesaas.php migrate run --module=egisz --format=json
cp modules/egisz/config.example.ini cfg/modules/egisz.ini
# edit: operator=n3, n3_guid, n3_id_lpu, demo URLs
```

Secrets stay in `cfg/modules/egisz.ini` (gitignored).

## Edge worker (Mac)

```bash
cp workers/plugins/egisz/configs/example.json workers/plugins/egisz/configs/local.json
# set server, worker_token (from /egisz/settings), n3.guid/id_lpu, vpn.ovpn path
# host.local.json: "enabled_plugins": [..., "egisz"]
brew install openvpn   # once
chmod +x workers/plugins/egisz/scripts/*.sh
./workers/bin/freesaas-workers plugin start egisz
# or: python3 workers/plugins/egisz/worker.py --config .../local.json
```

## Smoke on demomed (worker path)

1. Settings: `worker_enabled=1`, copy `worker_token` once  
2. Migrate `005_worker_jobs`  
3. Start Mac plugin + VPN  
4. `POST /egisz/patient/sync` → outbox `queued` → worker → `sent`  
5. Optional unsigned `POST /egisz/case/send` (REMD reject expected without UKEP)  
6. `POST /egisz/eventlog/poll` (queued to worker when dispatch on)

## Agent ops

- `egisz.status`, `egisz.settings.*`, `egisz.journal`
- `egisz.patient.sync`, `egisz.case.send`, `egisz.eventlog.poll`
- `egisz.icd.search`, `egisz.term.lookup`, `egisz.semd.preview*`

## Not in this slice

- Browser plugin / DSS UKEP  
- Prod ViPNet  
- Full NSI ICD dump / all SEMD kinds from dictionary 1520  
