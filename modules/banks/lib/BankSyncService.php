<?php
declare(strict_types=1);

/** Pull bank transactions and land them as ledger moves. */
final class BankSyncService
{
    /**
     * @return array{created:int,linked:int,skipped:int,errors:int,messages:list<string>}
     */
    public static function syncConnection(
        int $connectionId,
        string $dateFrom,
        string $dateTo,
        ?int $onlyLinkId = null
    ): array {
        $conn = BankConnectionsRepository::find($connectionId, true);
        if (!$conn) {
            throw new RuntimeException(t('banks.err.not_found'));
        }
        $provider = (string)$conn['provider'];
        $stats = ['created' => 0, 'linked' => 0, 'skipped' => 0, 'errors' => 0, 'messages' => []];
        if (BanksConfig::isFileProvider($provider)) {
            $stats['messages'][] = t('banks.sync.file_only');
            return $stats;
        }
        $links = BankAccountLinksRepository::forConnection($connectionId);
        $syncable = 0;
        $unlinked = 0;
        $disabled = 0;
        foreach ($links as $link) {
            if ($onlyLinkId !== null && (int)$link['id'] !== $onlyLinkId) {
                continue;
            }
            if ((int)$link['account_id'] < 1) {
                $unlinked++;
                continue;
            }
            if (!(int)$link['sync_enabled']) {
                $disabled++;
                continue;
            }
            $syncable++;
            try {
                $part = self::syncLink($provider, $connectionId, $link, $dateFrom, $dateTo);
                $stats['created'] += $part['created'];
                $stats['linked'] += $part['linked'];
                $stats['skipped'] += $part['skipped'];
                $stats['errors'] += $part['errors'];
                foreach ($part['messages'] as $m) {
                    $stats['messages'][] = $m;
                }
            } catch (Throwable $e) {
                $stats['errors']++;
                $stats['messages'][] = '#' . (int)$link['id'] . ': ' . $e->getMessage();
            }
        }
        if ($syncable === 0) {
            if ($links === []) {
                $stats['messages'][] = t('banks.sync.no_remote_accounts');
            } elseif ($unlinked > 0) {
                $stats['messages'][] = t('banks.sync.no_ledger_link', ['n' => $unlinked]);
            } elseif ($disabled > 0) {
                $stats['messages'][] = t('banks.sync.sync_disabled', ['n' => $disabled]);
            } else {
                $stats['messages'][] = t('banks.sync.nothing_to_sync');
            }
        }
        self::rememberLastSync($connectionId, $dateFrom, $dateTo, $stats);
        return $stats;
    }

    /**
     * @param array{created:int,linked:int,skipped:int,errors:int,messages:list<string>} $stats
     */
    private static function rememberLastSync(
        int $connectionId,
        string $dateFrom,
        string $dateTo,
        array $stats
    ): void {
        $conn = BankConnectionsRepository::find($connectionId, true);
        if (!$conn) {
            return;
        }
        $meta = [];
        $raw = $conn['meta'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        } elseif (is_array($raw)) {
            $meta = $raw;
        }
        $meta['last_sync'] = [
            'at' => time(),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'created' => (int)$stats['created'],
            'linked' => (int)$stats['linked'],
            'skipped' => (int)$stats['skipped'],
            'errors' => (int)$stats['errors'],
            'messages' => array_values(array_slice($stats['messages'], 0, 30)),
        ];
        BankConnectionsRepository::update($connectionId, ['meta' => $meta]);
    }

    /**
     * @return array{created:int,linked:int,skipped:int,errors:int,messages:list<string>}
     */
    public static function syncAllEnabled(string $dateFrom, string $dateTo): array
    {
        $stats = ['created' => 0, 'linked' => 0, 'skipped' => 0, 'errors' => 0, 'messages' => []];
        $byConn = [];
        foreach (BankAccountLinksRepository::syncEnabledLinks() as $link) {
            $cid = (int)$link['connection_id'];
            $byConn[$cid] = true;
        }
        foreach (array_keys($byConn) as $cid) {
            $part = self::syncConnection((int)$cid, $dateFrom, $dateTo, null);
            $stats['created'] += $part['created'];
            $stats['linked'] += $part['linked'];
            $stats['skipped'] += $part['skipped'];
            $stats['errors'] += $part['errors'];
            foreach ($part['messages'] as $m) {
                $stats['messages'][] = $m;
            }
        }
        return $stats;
    }

    /**
     * Sync all sync-enabled bank links mapped to one ledger account.
     *
     * @return array{created:int,linked:int,skipped:int,errors:int,messages:list<string>}
     */
    public static function syncLedgerAccount(int $accountId, string $dateFrom, string $dateTo): array
    {
        $stats = ['created' => 0, 'linked' => 0, 'skipped' => 0, 'errors' => 0, 'messages' => []];
        $links = BankAccountLinksRepository::forLedgerAccount($accountId);
        if ($links === []) {
            $stats['messages'][] = t('banks.sync.nothing_to_sync');
            return $stats;
        }
        foreach ($links as $link) {
            $part = self::syncConnection(
                (int)$link['connection_id'],
                $dateFrom,
                $dateTo,
                (int)$link['id']
            );
            $stats['created'] += $part['created'];
            $stats['linked'] += $part['linked'];
            $stats['skipped'] += $part['skipped'];
            $stats['errors'] += $part['errors'];
            foreach ($part['messages'] as $m) {
                $stats['messages'][] = $m;
            }
        }
        return $stats;
    }

    /**
     * Land already-normalized transactions as ledger moves (API sync + file import).
     *
     * @param array<string,mixed> $link
     * @param list<array{external_id:string,direction:int,amount:float,dt:int,purpose:string,counterparty_name:string,counterparty_inn:string,raw?:array}> $txs
     * @param array{llm?:bool,llm_budget_sec?:int,day_slack?:int,skip_purpose_regex?:string} $opts
     *        llm=false for bulk file import; day_slack expands same-amount match window (card ATM vs ФОТ dates);
     *        skip_purpose_regex — when set, matched purposes are claimed without creating a new ДПС
     * @return array{created:int,linked:int,skipped:int,skipped_purpose:int,errors:int,messages:list<string>}
     */
    public static function ingestNormalized(string $provider, array $link, array $txs, array $opts = []): array
    {
        $created = 0;
        $linked = 0;
        $skipped = 0;
        $skippedPurpose = 0;
        $errors = 0;
        $messages = [];
        $accountId = (int)($link['account_id'] ?? 0);
        $linkId = (int)($link['id'] ?? 0);
        $connectionId = (int)($link['connection_id'] ?? 0);
        if ($accountId < 1 || $linkId < 1) {
            throw new InvalidArgumentException(t('banks.err.link'));
        }
        $allowLlm = array_key_exists('llm', $opts) ? (bool)$opts['llm'] : true;
        $llmBudget = max(0, (int)($opts['llm_budget_sec'] ?? 20));
        $llmDeadline = $allowLlm && $llmBudget > 0 ? (time() + $llmBudget) : 0;
        $daySlack = max(0, min(31, (int)($opts['day_slack'] ?? 0)));
        // Only when explicitly passed (PSB file import). Do not read ini here —
        // API sync (Tochka/…) must keep creating moves for all unmatched rows.
        $skipRegex = trim((string)($opts['skip_purpose_regex'] ?? ''));
        $userId = Auth::userId() ?: 0;
        $now = time();
        $providerLabel = BanksConfig::providerLabel($provider);
        foreach ($txs as $tx) {
            $extId = (string)($tx['external_id'] ?? '');
            if ($extId === '') {
                $skipped++;
                continue;
            }
            if (BankTxMapRepository::moveIdFor($provider, $extId, $linkId) !== 0) {
                $skipped++;
                continue;
            }
            $claimKey = BankTxMapRepository::tryClaim($provider, $extId, $linkId, $connectionId);
            if ($claimKey === '') {
                $skipped++;
                continue;
            }
            try {
                $direction = (int)($tx['direction'] ?? 0) >= 0 ? 1 : -1;
                $amount = (float)($tx['amount'] ?? 0);
                $bankDt = (int)($tx['dt'] ?? 0) > 0 ? (int)$tx['dt'] : $now;
                $candidates = BankTxMapRepository::findMatchCandidates(
                    $accountId,
                    $amount,
                    $direction,
                    $bankDt,
                    5,
                    $daySlack
                );
                if (count($candidates) === 1) {
                    $moveId = (int)$candidates[0]['id'];
                    BankTxMapRepository::bindMove($provider, $claimKey, $moveId);
                    $note = t('banks.sync.linked_note', [
                        'provider' => $providerLabel,
                        'ext_id' => $extId,
                        'date' => date('d.m.Y', $bankDt),
                    ]);
                    LedgerMovesRepository::appendDescr($moveId, $note);
                    $linked++;
                    $messages[] = t('banks.sync.linked_log', [
                        'ext_id' => $extId,
                        'move_id' => $moveId,
                    ]);
                    continue;
                }
                if (count($candidates) > 1) {
                    $messages[] = t('banks.sync.ambiguous_log', [
                        'ext_id' => $extId,
                        'n' => count($candidates),
                    ]);
                }

                $purpose = trim((string)($tx['purpose'] ?? ''));
                if ($skipRegex !== '' && $purpose !== '' && @preg_match($skipRegex, $purpose) === 1) {
                    // Card cash-out: ФОТ/сейф is SoT — acknowledge without a second ДПС.
                    // Unique same-amount link already handled above; leftover N:M stays for later.
                    $skippedPurpose++;
                    $skipped++;
                    continue;
                }

                $useLlm = $allowLlm && ($llmDeadline === 0 || time() < $llmDeadline);
                $typeId = BankClassifyService::resolve($tx, $useLlm);
                if ($typeId < 1) {
                    throw new RuntimeException(t('banks.err.no_default_type'));
                }
                $descr = self::buildDescr($tx);
                $supplierId = self::resolveSupplierId($tx, $typeId);
                $moveId = LedgerMovesRepository::create([
                    'account_id' => $accountId,
                    'type_id' => $typeId,
                    'direction' => $direction,
                    'summ' => $amount,
                    'descr' => $descr,
                    'dt' => $bankDt,
                    'dt_create' => $now,
                    'user_create' => $userId,
                    'unconfirmed' => 0,
                    'payment_id' => 0,
                    'supplier_id' => $supplierId,
                    'user_payment' => 0,
                    'fund_movement_id' => 0,
                    'linked_id' => 0,
                    'account_to' => 0,
                ]);
                BankTxMapRepository::bindMove($provider, $claimKey, $moveId);
                $created++;
            } catch (Throwable $e) {
                BankTxMapRepository::releaseClaim($provider, $claimKey);
                $errors++;
                $messages[] = $extId . ': ' . $e->getMessage();
            }
        }
        return [
            'created' => $created,
            'linked' => $linked,
            'skipped' => $skipped,
            'skipped_purpose' => $skippedPurpose,
            'errors' => $errors,
            'messages' => $messages,
        ];
    }

    /**
     * Ensure an active file-provider connection + ledger link (SYNC_ENABLED=0).
     *
     * @return array<string,mixed> link row with provider
     */
    public static function ensureFileLink(int $accountId, string $provider, string $accountNumber = ''): array
    {
        $provider = BanksConfig::normalizeProvider($provider);
        if ($accountId < 1 || !BanksConfig::isFileProvider($provider)) {
            throw new InvalidArgumentException(t('banks.err.provider'));
        }
        if (!class_exists('LedgerAccountsRepository', false)) {
            throw new RuntimeException(t('banks.err.ledger_account'));
        }
        $acc = LedgerAccountsRepository::find($accountId);
        if (!$acc) {
            throw new RuntimeException(t('banks.err.ledger_account'));
        }

        $existing = BankAccountLinksRepository::fileLinkForLedgerAccount($accountId, $provider);
        if ($existing) {
            $num = preg_replace('/\D+/', '', $accountNumber) ?? '';
            if ($num !== '' && trim((string)($existing['account_number'] ?? '')) === '') {
                BankAccountLinksRepository::updateAccountNumber((int)$existing['id'], $num);
                $existing['account_number'] = $num;
                if (strpos((string)$existing['external_account_id'], 'ledger:') === 0) {
                    BankAccountLinksRepository::updateExternalId((int)$existing['id'], $num);
                    $existing['external_account_id'] = $num;
                }
            }
            return $existing;
        }

        $conn = BankConnectionsRepository::findActiveByProvider($provider);
        if (!$conn) {
            $connId = BankConnectionsRepository::create([
                'provider' => $provider,
                'status' => 'active',
                'label' => BanksConfig::providerLabel($provider),
                'user_id' => Auth::userId() ?: 0,
            ]);
        } else {
            $connId = (int)$conn['id'];
        }

        $num = preg_replace('/\D+/', '', $accountNumber) ?? '';
        $ext = $num !== '' ? $num : ('ledger:' . $accountId);
        $linkId = BankAccountLinksRepository::upsertRemote($connId, [
            'external_account_id' => $ext,
            'account_number' => $num,
            'currency' => 'RUB',
            'remote_name' => BanksConfig::providerLabel($provider),
        ]);
        BankAccountLinksRepository::linkToLedger($linkId, $accountId);
        BankAccountLinksRepository::setSyncEnabled($linkId, false);

        $link = BankAccountLinksRepository::find($linkId);
        if (!$link) {
            throw new RuntimeException(t('banks.err.link'));
        }
        $link['provider'] = $provider;
        return $link;
    }

    /**
     * Import a PSB card statement CSV into ledger moves for one account.
     *
     * @return array{
     *   created:int,linked:int,skipped:int,skipped_purpose:int,errors:int,
     *   messages:list<string>,account_number:string,tx_count:int,tx_new:int,
     *   recon:?array{id:int,bank_balance:float,updated:bool}
     * }
     */
    public static function importPsbCardFile(int $accountId, string $csv): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }
        $provider = 'psb_card';
        $link = BankAccountLinksRepository::fileLinkForLedgerAccount($accountId, $provider);
        if (!$link) {
            throw new RuntimeException(t('banks.import.err.not_bound'));
        }
        $parsed = PsbCardStatementParser::parse($csv);
        // Refresh account number from statement header when blank on the link.
        $fromFile = (string)$parsed['account_number'];
        if ($fromFile !== '' && trim((string)($link['account_number'] ?? '')) === '') {
            $link = self::ensureFileLink($accountId, $provider, $fromFile);
        }
        $allTxs = $parsed['txs'];
        // Re-evaluate prior purpose-skips (ID_MOVE=0) under current regex / match window.
        $released = BankTxMapRepository::releaseUnboundForLink($provider, (int)$link['id']);
        $sliceMessages = [];
        if ($released > 0) {
            // Full re-pass: incremental cut would drop earlier skips past the cursor.
            $txs = $allTxs;
            $sliceMessages[] = t('banks.import.revisit_skips', ['n' => $released]);
        } else {
            $sliced = self::sliceAfterLastKnown(
                $provider,
                (int)$link['id'],
                $allTxs,
                trim((string)($link['cursor_val'] ?? ''))
            );
            $txs = $sliced['txs'];
            if ($sliced['cut_after'] !== '') {
                $sliceMessages[] = t('banks.import.incremental', [
                    'after' => $sliced['cut_after'],
                    'new' => count($txs),
                    'total' => count($allTxs),
                ]);
            }
        }
        // No per-row LLM: FastCGI idle timeout is ~30s on typical hosts.
        // day_slack 14: ФОТ from сейф often posts days after the ATM row in the card statement.
        $stats = self::ingestNormalized($provider, $link, $txs, [
            'llm' => false,
            'day_slack' => 14,
            'skip_purpose_regex' => BanksConfig::fileSkipPurposeRegexSafe(),
        ]);
        foreach (array_reverse($sliceMessages) as $m) {
            array_unshift($stats['messages'], $m);
        }
        $accLabel = (string)($link['account_number'] !== '' ? $link['account_number'] : $link['external_account_id']);
        array_unshift($stats['messages'], t('banks.import.parsed', [
            'account' => $accLabel,
            'n' => count($allTxs),
        ]));
        $relink = self::relinkOrphanMoves($accountId, $provider, 14);
        if ($relink['relinked'] > 0 || $relink['deleted'] > 0) {
            $stats['messages'][] = t('banks.relink.done', [
                'relinked' => $relink['relinked'],
                'deleted' => $relink['deleted'],
                'left' => $relink['left'],
            ]);
            $stats['linked'] += $relink['relinked'];
        }

        $recon = null;
        $closing = $parsed['closing_balance'];
        if ($closing !== null && class_exists('LedgerReconciliationsRepository', false)) {
            $recon = self::applyClosingBalanceRecon(
                $accountId,
                (float)$closing,
                (string)($parsed['period_to'] ?? '')
            );
            if ($recon !== null) {
                $stats['messages'][] = t(
                    !empty($recon['updated']) ? 'banks.import.recon_updated' : 'banks.import.recon_created',
                    ['balance' => number_format((float)$recon['bank_balance'], 2, ',', ' ')]
                );
            }
        }

        $lastExt = '';
        if ($allTxs !== []) {
            $chrono = $allTxs;
            usort($chrono, static function (array $a, array $b): int {
                $dt = ((int)($a['dt'] ?? 0)) <=> ((int)($b['dt'] ?? 0));
                return $dt !== 0 ? $dt : strcmp((string)($a['external_id'] ?? ''), (string)($b['external_id'] ?? ''));
            });
            $lastExt = (string)($chrono[count($chrono) - 1]['external_id'] ?? '');
        }
        BankAccountLinksRepository::markSynced((int)$link['id'], $lastExt !== '' ? $lastExt : date('Y-m-d'));
        $stats['account_number'] = $fromFile !== '' ? $fromFile : (string)($link['account_number'] ?? '');
        $stats['tx_count'] = count($allTxs);
        $stats['tx_new'] = count($txs);
        $stats['recon'] = $recon;
        if (!isset($stats['skipped_purpose'])) {
            $stats['skipped_purpose'] = 0;
        }
        return $stats;
    }

    /**
     * Keep only txs after the chronologically latest already-mapped row found in this file.
     * Source of truth = base_bank_tx_map only (not CURSOR_VAL): a stale cursor after map
     * rollback would otherwise slice the whole file to empty.
     *
     * @param list<array{external_id:string,dt?:int}> $txs
     * @return array{txs:list<array>,cut_after:string,messages:list<string>}
     */
    private static function sliceAfterLastKnown(
        string $provider,
        int $linkId,
        array $txs,
        string $cursor = ''
    ): array {
        unset($cursor); // kept in signature for call-site clarity; map is authoritative
        if ($txs === []) {
            return ['txs' => [], 'cut_after' => '', 'messages' => []];
        }
        $known = BankTxMapRepository::knownExternalIdSet($provider, $linkId);
        if ($known === []) {
            return ['txs' => $txs, 'cut_after' => '', 'messages' => []];
        }
        $indexed = [];
        foreach ($txs as $i => $tx) {
            $indexed[] = [
                'i' => $i,
                'ext' => (string)($tx['external_id'] ?? ''),
                'dt' => (int)($tx['dt'] ?? 0),
                'tx' => $tx,
            ];
        }
        usort($indexed, static function (array $a, array $b): int {
            $dt = $a['dt'] <=> $b['dt'];
            return $dt !== 0 ? $dt : strcmp($a['ext'], $b['ext']);
        });
        $cutPos = -1;
        $cutAfter = '';
        foreach ($indexed as $pos => $row) {
            if ($row['ext'] !== '' && isset($known[$row['ext']])) {
                $cutPos = $pos;
                $cutAfter = $row['ext'];
            }
        }
        if ($cutPos < 0) {
            return ['txs' => $txs, 'cut_after' => '', 'messages' => []];
        }
        $out = [];
        for ($p = $cutPos + 1, $n = count($indexed); $p < $n; $p++) {
            $out[] = $indexed[$p]['tx'];
        }
        return ['txs' => $out, 'cut_after' => $cutAfter, 'messages' => []];
    }

    /**
     * Write statement «Исходящий остаток» into an active reconciliation for the statement end day.
     *
     * @return array{id:int,bank_balance:float,updated:bool}|null
     */
    private static function applyClosingBalanceRecon(
        int $accountId,
        float $bankBalance,
        string $periodTo
    ): ?array {
        if ($accountId < 1 || !Database::tableExists('base_account_reconciliations')) {
            return null;
        }
        $dayStart = self::parseStatementDayStart($periodTo);
        if ($dayStart < 1) {
            $dayStart = mktime(0, 0, 0) ?: time();
        }
        $userId = Auth::userId() ?: 0;
        $existing = LedgerReconciliationsRepository::latestOnDay($accountId, $dayStart);
        if ($existing) {
            LedgerReconciliationsRepository::updateBank(
                (int)$existing['id'],
                $accountId,
                $bankBalance,
                true
            );
            return [
                'id' => (int)$existing['id'],
                'bank_balance' => $bankBalance,
                'updated' => true,
            ];
        }
        $dt = $dayStart + 12 * 3600;
        $id = LedgerReconciliationsRepository::create($accountId, $bankBalance, $userId, $dt);
        return [
            'id' => $id,
            'bank_balance' => $bankBalance,
            'updated' => false,
        ];
    }

    /** dd.mm.YYYY or Y-m-d → local midnight. */
    private static function parseStatementDayStart(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return 0;
        }
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $raw, $m)) {
            $ts = mktime(0, 0, 0, (int)$m[2], (int)$m[1], (int)$m[3]);
            return $ts !== false ? $ts : 0;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
            $ts = mktime(0, 0, 0, (int)$m[2], (int)$m[3], (int)$m[1]);
            return $ts !== false ? $ts : 0;
        }
        return 0;
    }

    /**
     * Re-attach bank-created default-type moves to unique same-amount ДПС in a date window
     * (e.g. ATM «Прочее» → existing ФОТ Нал), then soft-delete the orphan duplicate.
     *
     * @return array{relinked:int,deleted:int,left:int,messages:list<string>}
     */
    public static function relinkOrphanMoves(int $accountId, string $provider, int $daySlack = 3): array
    {
        $relinked = 0;
        $deleted = 0;
        $messages = [];
        if ($accountId < 1 || $provider === '' || !BankTxMapRepository::ready()) {
            return ['relinked' => 0, 'deleted' => 0, 'left' => 0, 'messages' => []];
        }
        $defaultType = BanksConfig::defaultMoveTypeId();
        if ($defaultType < 1) {
            return ['relinked' => 0, 'deleted' => 0, 'left' => 0, 'messages' => []];
        }
        $daySlack = max(0, min(31, $daySlack));
        $st = Database::pdo()->prepare(
            'SELECT m.ID AS id, m.SUMM AS summ, m.DIRECTION AS direction, m.DT AS dt,
                    map.EXTERNAL_TX_ID AS external_tx_id
             FROM base_bank_tx_map map
             INNER JOIN base_account_moves m ON m.ID = map.ID_MOVE
             WHERE map.PROVIDER = ?
               AND m.ID_ACCOUNT = ?
               AND m.DELETED = 0
               AND m.ID_TYPE = ?
               AND map.ID_MOVE > 0
             ORDER BY m.DT ASC, m.ID ASC'
        );
        $st->execute([$provider, $accountId, $defaultType]);
        $orphans = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $providerLabel = BanksConfig::providerLabel($provider);
        foreach ($orphans as $orphan) {
            $orphanId = (int)$orphan['id'];
            $amount = (float)$orphan['summ'];
            $direction = (int)$orphan['direction'] >= 0 ? 1 : -1;
            $bankDt = (int)$orphan['dt'];
            $extId = (string)$orphan['external_tx_id'];
            $candidates = BankTxMapRepository::findMatchCandidates(
                $accountId,
                $amount,
                $direction,
                $bankDt,
                5,
                $daySlack
            );
            if (count($candidates) !== 1) {
                continue;
            }
            $targetId = (int)$candidates[0]['id'];
            if ($targetId < 1 || $targetId === $orphanId) {
                continue;
            }
            if (!BankTxMapRepository::retargetMove($provider, $orphanId, $targetId)) {
                continue;
            }
            $note = t('banks.sync.linked_note', [
                'provider' => $providerLabel,
                'ext_id' => $extId !== '' ? $extId : ('move:' . $orphanId),
                'date' => date('d.m.Y', $bankDt),
            ]);
            LedgerMovesRepository::appendDescr($targetId, $note);
            $del = LedgerMovesRepository::softDeleteByIds([$orphanId]);
            $relinked++;
            $deleted += (int)($del['done'] ?? 0);
            $messages[] = t('banks.relink.item', [
                'orphan' => $orphanId,
                'target' => $targetId,
                'summ' => $amount,
            ]);
        }
        $leftSt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM base_bank_tx_map map
             INNER JOIN base_account_moves m ON m.ID = map.ID_MOVE
             WHERE map.PROVIDER = ? AND m.ID_ACCOUNT = ? AND m.DELETED = 0 AND m.ID_TYPE = ?'
        );
        $leftSt->execute([$provider, $accountId, $defaultType]);
        $left = (int)$leftSt->fetchColumn();
        return compact('relinked', 'deleted', 'left', 'messages');
    }

    /**
     * @param array<string,mixed> $link
     * @return array{created:int,linked:int,skipped:int,errors:int,messages:list<string>}
     */
    private static function syncLink(
        string $provider,
        int $connectionId,
        array $link,
        string $dateFrom,
        string $dateTo
    ): array {
        $txs = BankOAuth::fetchTransactions(
            $connectionId,
            (string)$link['external_account_id'],
            $dateFrom,
            $dateTo
        );
        $accLabel = (string)($link['account_number'] !== '' ? $link['account_number'] : $link['external_account_id']);
        $stats = self::ingestNormalized($provider, $link, $txs);
        array_unshift($stats['messages'], t('banks.sync.fetched', [
            'account' => $accLabel,
            'n' => count($txs),
            'from' => $dateFrom,
            'to' => $dateTo,
        ]));
        BankAccountLinksRepository::markSynced((int)$link['id'], $dateTo);
        return $stats;
    }

    /** @param array{purpose?:string,counterparty_name?:string,counterparty_inn?:string} $tx */
    private static function buildDescr(array $tx): string
    {
        $parts = [];
        $cp = trim((string)($tx['counterparty_name'] ?? ''));
        $inn = trim((string)($tx['counterparty_inn'] ?? ''));
        if ($cp !== '') {
            $parts[] = $cp . ($inn !== '' ? ' (ИНН ' . $inn . ')' : '');
        }
        $purpose = trim((string)($tx['purpose'] ?? ''));
        if ($purpose !== '') {
            $parts[] = $purpose;
        }
        return $parts !== [] ? implode(' — ', $parts) : 'bank';
    }

    /**
     * Auto-link supplier by bank INN when move type allows counterparties
     * and exactly one matching supplier exists in the system.
     *
     * @param array{counterparty_inn?:string} $tx
     */
    private static function resolveSupplierId(array $tx, int $typeId): int
    {
        if ($typeId < 1 || !class_exists('SuppliersRepository', false)) {
            return 0;
        }
        $type = AccountMoveTypesRepository::find($typeId);
        if (!$type || empty($type['supplier_enabled'])) {
            return 0;
        }
        $inn = trim((string)($tx['counterparty_inn'] ?? ''));
        if ($inn === '') {
            return 0;
        }
        return SuppliersRepository::findIdByInn($inn);
    }
}
