<?php

namespace Onekana\Api\Repositories;

use Onekana\Api\Finance\Money;
use Onekana\Api\Http\HttpException;
use Onekana\Api\Support\Clock;
use PDO;
use Throwable;

final class FinanceRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function accounts(int $tenantId): array { return array_map([$this, 'presentAccount'], $this->all('accounting_accounts', $tenantId, 'code ASC')); }
    public function journals(int $tenantId): array { return array_map([$this, 'presentJournal'], $this->all('accounting_journals', $tenantId, 'code ASC')); }
    public function periods(int $tenantId): array { return array_map([$this, 'presentPeriod'], $this->all('accounting_periods', $tenantId, 'starts_on DESC')); }

    public function createAccount(int $tenantId, array $data): array
    {
        $code = trim((string) ($data['code'] ?? ''));
        $class = (int) ($code[0] ?? 0);
        if (! preg_match('/^[1-8][0-9A-Za-z.-]{0,31}$/', $code) || $class < 1 || $class > 8) throw new HttpException(422, 'Le code doit appartenir à une classe SYSCOHADA de 1 à 8.');
        $label = trim((string) ($data['label'] ?? $data['libelle'] ?? ''));
        if ($label === '') throw new HttpException(422, 'Le libellé est obligatoire.');
        $type = (string) ($data['type'] ?? 'asset');
        $this->insert('accounting_accounts', ['tenant_id' => $tenantId, 'code' => $code, 'label' => $label, 'class' => $class, 'type' => $type, 'is_active' => 1, 'created_at' => Clock::now(), 'updated_at' => Clock::now()]);
        return $this->presentAccount($this->find('accounting_accounts', (int) $this->pdo->lastInsertId(), $tenantId));
    }

    public function importAccounts(int $tenantId, array $accounts): array
    {
        if ($accounts === [] || count($accounts) > 1000) throw new HttpException(422, 'Le fichier doit contenir entre 1 et 1000 comptes.');
        $created = 0;
        $this->transaction(function () use ($tenantId, $accounts, &$created) {
            foreach ($accounts as $account) {
                if (! is_array($account)) throw new HttpException(422, 'Format de compte invalide.');
                $this->createAccount($tenantId, $account);
                $created++;
            }
        });
        return ['imported' => $created];
    }

    public function deleteAccount(int $tenantId, int $id): void
    {
        $this->deleteUnused('accounting_accounts', $tenantId, $id, 'accounting_entry_lines', 'account_id');
    }

    public function createJournal(int $tenantId, array $data): array
    {
        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        $name = trim((string) ($data['name'] ?? $data['nom'] ?? ''));
        if (! preg_match('/^[A-Z0-9_-]{1,16}$/', $code) || $name === '') throw new HttpException(422, 'Le code et le nom du journal sont obligatoires.');
        $this->insert('accounting_journals', ['tenant_id' => $tenantId, 'code' => $code, 'name' => $name, 'type' => (string) ($data['type'] ?? 'od'), 'is_active' => 1, 'created_at' => Clock::now(), 'updated_at' => Clock::now()]);
        return $this->presentJournal($this->find('accounting_journals', (int) $this->pdo->lastInsertId(), $tenantId));
    }

    public function deleteJournal(int $tenantId, int $id): void
    {
        $this->deleteUnused('accounting_journals', $tenantId, $id, 'accounting_entries', 'journal_id');
    }

    public function createPeriod(int $tenantId, array $data): array
    {
        $start = $this->date((string) ($data['startsOn'] ?? $data['starts_on'] ?? ''));
        $end = $this->date((string) ($data['endsOn'] ?? $data['ends_on'] ?? ''));
        if ($end < $start) throw new HttpException(422, 'La fin de période doit suivre son début.');
        $this->insert('accounting_periods', ['tenant_id' => $tenantId, 'label' => trim((string) ($data['label'] ?? $start.' - '.$end)), 'starts_on' => $start, 'ends_on' => $end, 'status' => 'open', 'created_at' => Clock::now(), 'updated_at' => Clock::now()]);
        return $this->presentPeriod($this->find('accounting_periods', (int) $this->pdo->lastInsertId(), $tenantId));
    }

    public function closePeriod(int $tenantId, int $id): array
    {
        $period = $this->find('accounting_periods', $id, $tenantId);
        if (! $period) throw new HttpException(404, 'Période introuvable.');
        $statement = $this->pdo->prepare("UPDATE accounting_periods SET status = 'closed', closed_at = :now, updated_at = :now WHERE id = :id AND tenant_id = :tenant");
        $statement->execute(['now' => Clock::now(), 'id' => $id, 'tenant' => $tenantId]);
        return $this->presentPeriod($this->find('accounting_periods', $id, $tenantId));
    }

    public function entries(int $tenantId): array
    {
        $rows = $this->all('accounting_entries', $tenantId, 'entry_date DESC, id DESC');
        return array_map(fn (array $row) => $this->presentEntry($row), $rows);
    }

    public function createEntry(int $tenantId, int $userId, array $data): array
    {
        return $this->transaction(fn () => $this->insertEntry($tenantId, $userId, $data));
    }

    public function reverseEntry(int $tenantId, int $userId, int $id, string $reason): array
    {
        return $this->transaction(function () use ($tenantId, $userId, $id, $reason) {
            $entry = $this->find('accounting_entries', $id, $tenantId);
            if (! $entry || $entry['status'] !== 'posted') throw new HttpException(422, 'Seule une écriture validée peut être contrepassée.');
            $lines = $this->entryLines($id);
            $reversal = $this->insertEntry($tenantId, $userId, [
                'date' => gmdate('Y-m-d'), 'journalId' => $entry['journal_id'],
                'reference' => 'REV-'.$entry['reference'].'-'.gmdate('YmdHis'),
                'label' => 'Contrepassation: '.($reason !== '' ? $reason : $entry['label']),
                'lines' => array_map(fn ($line) => ['accountId' => $line['account_id'], 'label' => $line['label'], 'debit' => $line['credit'], 'credit' => $line['debit']], $lines),
            ]);
            $statement = $this->pdo->prepare("UPDATE accounting_entries SET status = 'reversed', reversed_entry_id = :reversal, updated_at = :now WHERE id = :id");
            $statement->execute(['reversal' => $reversal['id'], 'now' => Clock::now(), 'id' => $id]);
            return $reversal;
        });
    }

    public function trialBalance(int $tenantId): array
    {
        $statement = $this->pdo->prepare("SELECT a.id, a.code, a.label, COALESCE(SUM(l.debit),0) debit, COALESCE(SUM(l.credit),0) credit FROM accounting_accounts a LEFT JOIN accounting_entry_lines l ON l.account_id = a.id LEFT JOIN accounting_entries e ON e.id = l.entry_id AND e.status IN ('posted','reversed') WHERE a.tenant_id = :tenant GROUP BY a.id, a.code, a.label ORDER BY a.code");
        $statement->execute(['tenant' => $tenantId]);
        return array_map(function (array $row) {
            $debit = Money::cents($row['debit']); $credit = Money::cents($row['credit']);
            return ['accountId' => (string) $row['id'], 'accountCode' => $row['code'], 'accountLabel' => $row['label'], 'debit' => Money::decimal($debit), 'credit' => Money::decimal($credit), 'balance' => Money::decimal($debit - $credit)];
        }, $statement->fetchAll());
    }

    public function settings(int $tenantId): array
    {
        $row = $this->one('SELECT * FROM finance_settings WHERE tenant_id = :tenant', ['tenant' => $tenantId]);
        return $row ? $this->presentSettings($row) : ['configured' => false];
    }

    public function saveSettings(int $tenantId, array $data): array
    {
        $map = ['salesAccountId' => 'sales_account_id', 'receivableAccountId' => 'receivable_account_id', 'taxAccountId' => 'tax_account_id', 'bankAccountId' => 'bank_account_id', 'walletAccountId' => 'wallet_account_id', 'expenseAccountId' => 'expense_account_id'];
        $attributes = [];
        foreach ($map as $input => $column) {
            $id = (int) ($data[$input] ?? 0);
            if ($id && ! $this->find('accounting_accounts', $id, $tenantId)) throw new HttpException(422, 'Un compte de configuration est invalide.');
            $attributes[$column] = $id ?: null;
        }
        $existing = $this->one('SELECT id FROM finance_settings WHERE tenant_id = :tenant', ['tenant' => $tenantId]);
        $now = Clock::now();
        if ($existing) {
            $sets = implode(', ', array_map(fn ($key) => "{$key} = :{$key}", array_keys($attributes)));
            $this->pdo->prepare("UPDATE finance_settings SET {$sets}, configured_at = :now, updated_at = :now WHERE tenant_id = :tenant")->execute([...$attributes, 'now' => $now, 'tenant' => $tenantId]);
        } else {
            $this->insert('finance_settings', ['tenant_id' => $tenantId, ...$attributes, 'configured_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        }
        return $this->settings($tenantId);
    }

    public function invoices(int $tenantId): array { return array_map([$this, 'presentInvoice'], $this->all('invoices', $tenantId, 'issue_date DESC, id DESC')); }

    public function createInvoice(int $tenantId, array $data): array
    {
        return $this->transaction(function () use ($tenantId, $data) {
            $lines = $data['lines'] ?? [];
            if (! is_array($lines) || $lines === []) {
                $lines = [['description' => (string) ($data['description'] ?? 'Prestation ONEKANA'), 'quantity' => '1.00', 'unitPrice' => $data['subtotal'] ?? $data['amount'] ?? $data['total'] ?? '0.00']];
            }
            $subtotal = 0;
            foreach ($lines as &$line) {
                $quantity = Money::cents($line['quantity'] ?? '1.00');
                $unit = Money::cents($line['unitPrice'] ?? $line['unit_price'] ?? '0.00');
                if ($quantity <= 0 || $unit < 0) throw new HttpException(422, 'Les lignes de facture contiennent un montant invalide.');
                $line['lineCents'] = intdiv($quantity * $unit, 100);
                $subtotal += $line['lineCents'];
            }
            unset($line);
            $tax = Money::cents($data['tax'] ?? $data['taxe'] ?? '0.00', 'tax');
            $total = $subtotal + $tax;
            if ($total <= 0) throw new HttpException(422, 'Le total de la facture doit être positif.');
            $clientName = trim((string) ($data['clientName'] ?? $data['client_name'] ?? ''));
            if ($clientName === '') throw new HttpException(422, 'Le client est obligatoire.');
            $issueDate = $this->date((string) ($data['issueDate'] ?? $data['issue_date'] ?? gmdate('Y-m-d')));
            $number = trim((string) ($data['number'] ?? $data['numero'] ?? 'INV-'.gmdate('Ymd-His').'-'.random_int(100, 999)));
            $this->insert('invoices', ['tenant_id' => $tenantId, 'number' => $number, 'client_name' => $clientName, 'client_reference' => $data['clientReference'] ?? null, 'campaign_reference' => $data['campaignReference'] ?? $data['campaign_id'] ?? null, 'issue_date' => $issueDate, 'due_date' => $data['dueDate'] ?? $data['due_date'] ?? null, 'currency' => 'USD', 'subtotal' => Money::decimal($subtotal), 'tax' => Money::decimal($tax), 'total' => Money::decimal($total), 'balance' => Money::decimal($total), 'status' => 'draft', 'created_at' => Clock::now(), 'updated_at' => Clock::now()]);
            $invoiceId = (int) $this->pdo->lastInsertId();
            foreach ($lines as $line) $this->insert('invoice_lines', ['invoice_id' => $invoiceId, 'description' => trim((string) ($line['description'] ?? 'Prestation')), 'quantity' => Money::output($line['quantity'] ?? '1.00'), 'unit_price' => Money::output($line['unitPrice'] ?? $line['unit_price'] ?? '0.00'), 'tax_rate' => (string) ($line['taxRate'] ?? '0.0000'), 'line_total' => Money::decimal($line['lineCents']), 'created_at' => Clock::now()]);
            return $this->presentInvoice($this->find('invoices', $invoiceId, $tenantId));
        });
    }

    public function issueInvoice(int $tenantId, int $userId, int $id): array
    {
        return $this->transaction(function () use ($tenantId, $userId, $id) {
            $invoice = $this->find('invoices', $id, $tenantId);
            if (! $invoice) throw new HttpException(404, 'Facture introuvable.');
            if ($invoice['status'] !== 'draft') return $this->presentInvoice($invoice);
            $settings = $this->requiredSettings($tenantId, ['sales_account_id', 'receivable_account_id']);
            $lines = [['accountId' => $settings['receivable_account_id'], 'debit' => $invoice['total'], 'credit' => '0.00'], ['accountId' => $settings['sales_account_id'], 'debit' => '0.00', 'credit' => Money::decimal(Money::cents($invoice['subtotal']))]];
            if (Money::cents($invoice['tax']) > 0) {
                if (! $settings['tax_account_id']) throw new HttpException(422, 'Le compte de taxe doit être configuré avant validation.');
                $lines[] = ['accountId' => $settings['tax_account_id'], 'debit' => '0.00', 'credit' => $invoice['tax']];
            }
            $entry = $this->insertEntry($tenantId, $userId, ['date' => $invoice['issue_date'], 'journalId' => $this->journalFor($tenantId, 'vente'), 'reference' => 'INV-'.$invoice['number'], 'label' => 'Facture '.$invoice['number'], 'lines' => $lines]);
            $this->pdo->prepare("UPDATE invoices SET status = 'issued', posted_entry_id = :entry, updated_at = :now WHERE id = :id")->execute(['entry' => $entry['id'], 'now' => Clock::now(), 'id' => $id]);
            return $this->presentInvoice($this->find('invoices', $id, $tenantId));
        });
    }

    public function payments(int $tenantId): array { return array_map([$this, 'presentPayment'], $this->all('payments', $tenantId, 'paid_at DESC, id DESC')); }

    public function createPayment(int $tenantId, int $userId, array $data): array
    {
        return $this->transaction(function () use ($tenantId, $userId, $data) {
            $idempotency = trim((string) ($data['idempotencyKey'] ?? $data['idempotency_key'] ?? $data['reference'] ?? ''));
            if ($idempotency === '') throw new HttpException(422, 'Une référence unique est obligatoire.');
            $existing = $this->one('SELECT * FROM payments WHERE tenant_id = :tenant AND idempotency_key = :key', ['tenant' => $tenantId, 'key' => $idempotency]);
            if ($existing) return $this->presentPayment($existing);
            $invoice = $this->find('invoices', (int) ($data['invoiceId'] ?? $data['invoice_id'] ?? 0), $tenantId);
            if (! $invoice || $invoice['status'] === 'draft') throw new HttpException(422, 'La facture doit être validée avant son paiement.');
            $amount = Money::cents($data['amount'] ?? '0.00');
            if ($amount <= 0 || $amount > Money::cents($invoice['balance'])) throw new HttpException(422, 'Le montant du paiement est invalide.');
            $method = (string) ($data['method'] ?? 'bank');
            $cashKey = $method === 'wallet' ? 'wallet_account_id' : 'bank_account_id';
            $settings = $this->requiredSettings($tenantId, ['receivable_account_id', $cashKey]);
            $entry = $this->insertEntry($tenantId, $userId, ['date' => substr((string) ($data['paidAt'] ?? gmdate('Y-m-d')), 0, 10), 'journalId' => $this->journalFor($tenantId, 'banque'), 'reference' => 'PAY-'.$idempotency, 'label' => 'Paiement '.$invoice['number'], 'lines' => [['accountId' => $settings[$cashKey], 'debit' => Money::decimal($amount), 'credit' => '0.00'], ['accountId' => $settings['receivable_account_id'], 'debit' => '0.00', 'credit' => Money::decimal($amount)]]]);
            $this->insert('payments', ['tenant_id' => $tenantId, 'invoice_id' => $invoice['id'], 'wallet_account_id' => $data['walletAccountId'] ?? null, 'amount' => Money::decimal($amount), 'method' => $method, 'reference' => (string) ($data['reference'] ?? $idempotency), 'paid_at' => Clock::now(), 'status' => 'posted', 'posted_entry_id' => $entry['id'], 'idempotency_key' => $idempotency, 'created_at' => Clock::now(), 'updated_at' => Clock::now()]);
            $balance = Money::cents($invoice['balance']) - $amount;
            $this->pdo->prepare('UPDATE invoices SET balance = :balance, status = :status, updated_at = :now WHERE id = :id')->execute(['balance' => Money::decimal($balance), 'status' => $balance === 0 ? 'paid' : 'partial', 'now' => Clock::now(), 'id' => $invoice['id']]);
            return $this->presentPayment($this->find('payments', (int) $this->pdo->lastInsertId(), $tenantId));
        });
    }

    public function walletAccounts(int $tenantId): array
    {
        $rows = $this->all('wallet_accounts', $tenantId, 'name ASC');
        return array_map(function (array $row) use ($tenantId) {
            $statement = $this->pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type = 'inflow' THEN amount ELSE -amount END),0) FROM wallet_transactions WHERE tenant_id = :tenant AND wallet_account_id = :account AND status = 'posted'");
            $statement->execute(['tenant' => $tenantId, 'account' => $row['id']]);
            return ['id' => (string) $row['id'], 'name' => $row['name'], 'code' => $row['code'], 'currency' => 'USD', 'status' => $row['status'], 'balance' => Money::output($statement->fetchColumn())];
        }, $rows);
    }

    public function createWalletAccount(int $tenantId, array $data): array
    {
        $name = trim((string) ($data['name'] ?? '')); $code = strtoupper(trim((string) ($data['code'] ?? '')));
        if ($name === '' || ! preg_match('/^[A-Z0-9_-]{1,32}$/', $code)) throw new HttpException(422, 'Le nom et le code du compte Wallet sont obligatoires.');
        $this->insert('wallet_accounts', ['tenant_id' => $tenantId, 'name' => $name, 'code' => $code, 'currency' => 'USD', 'status' => 'active', 'created_at' => Clock::now(), 'updated_at' => Clock::now()]);
        $id = (int) $this->pdo->lastInsertId();
        return array_values(array_filter($this->walletAccounts($tenantId), fn (array $account) => (int) $account['id'] === $id))[0];
    }

    public function walletTransactions(int $tenantId): array { return array_map([$this, 'presentWalletTransaction'], $this->all('wallet_transactions', $tenantId, 'occurred_at DESC, id DESC')); }

    public function createWalletTransaction(int $tenantId, int $userId, array $data): array
    {
        return $this->transaction(function () use ($tenantId, $userId, $data) {
            $accountId = (int) ($data['walletAccountId'] ?? $data['wallet_account_id'] ?? 0);
            if (! $this->find('wallet_accounts', $accountId, $tenantId)) throw new HttpException(422, 'Compte Wallet invalide.');
            $type = (string) ($data['type'] ?? '');
            if (! in_array($type, ['inflow', 'outflow'], true)) throw new HttpException(422, 'Type de mouvement invalide.');
            $amount = Money::cents($data['amount'] ?? '0.00'); if ($amount <= 0) throw new HttpException(422, 'Le montant doit être positif.');
            $idempotency = trim((string) ($data['idempotencyKey'] ?? $data['reference'] ?? '')); if ($idempotency === '') throw new HttpException(422, 'Une référence unique est obligatoire.');
            $existing = $this->one('SELECT * FROM wallet_transactions WHERE tenant_id = :tenant AND idempotency_key = :key', ['tenant' => $tenantId, 'key' => $idempotency]); if ($existing) return $this->presentWalletTransaction($existing);
            $settings = $this->requiredSettings($tenantId, [$type === 'outflow' ? 'expense_account_id' : 'receivable_account_id', 'wallet_account_id']);
            $counterpart = $settings[$type === 'outflow' ? 'expense_account_id' : 'receivable_account_id']; $wallet = $settings['wallet_account_id'];
            $entry = $this->insertEntry($tenantId, $userId, ['date' => gmdate('Y-m-d'), 'journalId' => $this->journalFor($tenantId, 'banque'), 'reference' => 'WAL-'.$idempotency, 'label' => (string) ($data['source'] ?? 'Mouvement Wallet'), 'lines' => $type === 'outflow' ? [['accountId' => $counterpart, 'debit' => Money::decimal($amount), 'credit' => '0.00'], ['accountId' => $wallet, 'debit' => '0.00', 'credit' => Money::decimal($amount)]] : [['accountId' => $wallet, 'debit' => Money::decimal($amount), 'credit' => '0.00'], ['accountId' => $counterpart, 'debit' => '0.00', 'credit' => Money::decimal($amount)]]]);
            $this->insert('wallet_transactions', ['tenant_id' => $tenantId, 'wallet_account_id' => $accountId, 'type' => $type, 'amount' => Money::decimal($amount), 'source' => $data['source'] ?? null, 'reference' => (string) ($data['reference'] ?? $idempotency), 'status' => 'posted', 'occurred_at' => Clock::now(), 'posted_entry_id' => $entry['id'], 'idempotency_key' => $idempotency, 'created_at' => Clock::now(), 'updated_at' => Clock::now()]);
            return $this->presentWalletTransaction($this->find('wallet_transactions', (int) $this->pdo->lastInsertId(), $tenantId));
        });
    }

    private function insertEntry(int $tenantId, int $userId, array $data): array
    {
        $date = $this->date((string) ($data['date'] ?? gmdate('Y-m-d')));
        $period = $this->openPeriod($tenantId, $date);
        $journal = $this->find('accounting_journals', (int) ($data['journalId'] ?? $data['journal_id'] ?? 0), $tenantId);
        if (! $journal || ! $journal['is_active']) throw new HttpException(422, 'Journal comptable invalide.');
        $lines = $data['lines'] ?? [];
        if (! is_array($lines) || count($lines) < 2) throw new HttpException(422, 'Une écriture doit contenir au moins deux lignes.');
        $debit = 0; $credit = 0;
        foreach ($lines as $line) {
            if (! $this->find('accounting_accounts', (int) ($line['accountId'] ?? $line['account_id'] ?? 0), $tenantId)) throw new HttpException(422, 'Compte comptable invalide.');
            $lineDebit = Money::cents($line['debit'] ?? '0.00'); $lineCredit = Money::cents($line['credit'] ?? '0.00');
            if ($lineDebit < 0 || $lineCredit < 0 || ($lineDebit > 0) === ($lineCredit > 0)) throw new HttpException(422, 'Chaque ligne doit contenir un débit ou un crédit positif.');
            $debit += $lineDebit; $credit += $lineCredit;
        }
        if ($debit === 0 || $debit !== $credit) throw new HttpException(422, 'Le total débit doit être égal au total crédit.');
        $reference = trim((string) ($data['reference'] ?? 'MAN-'.gmdate('Ymd-His').'-'.random_int(100, 999)));
        $this->insert('accounting_entries', ['tenant_id' => $tenantId, 'journal_id' => $journal['id'], 'period_id' => $period['id'], 'reference' => $reference, 'entry_date' => $date, 'label' => trim((string) ($data['label'] ?? $data['libelle'] ?? 'Écriture comptable')), 'status' => 'posted', 'posted_at' => Clock::now(), 'created_by' => $userId, 'created_at' => Clock::now(), 'updated_at' => Clock::now()]);
        $entryId = (int) $this->pdo->lastInsertId();
        foreach ($lines as $line) $this->insert('accounting_entry_lines', ['entry_id' => $entryId, 'account_id' => (int) ($line['accountId'] ?? $line['account_id']), 'label' => $line['label'] ?? null, 'debit' => Money::output($line['debit'] ?? '0.00'), 'credit' => Money::output($line['credit'] ?? '0.00'), 'created_at' => Clock::now()]);
        return $this->presentEntry($this->find('accounting_entries', $entryId, $tenantId));
    }

    private function openPeriod(int $tenantId, string $date): array
    {
        $period = $this->one("SELECT * FROM accounting_periods WHERE tenant_id = :tenant AND starts_on <= :date AND ends_on >= :date AND status = 'open' ORDER BY id DESC LIMIT 1", ['tenant' => $tenantId, 'date' => $date]);
        if ($period) return $period;
        $closed = $this->one("SELECT id FROM accounting_periods WHERE tenant_id = :tenant AND starts_on <= :date AND ends_on >= :date AND status = 'closed' LIMIT 1", ['tenant' => $tenantId, 'date' => $date]);
        if ($closed) throw new HttpException(422, 'La période comptable est clôturée.');
        $year = substr($date, 0, 4);
        return $this->rawPeriod($tenantId, "Exercice {$year}", "{$year}-01-01", "{$year}-12-31");
    }

    private function rawPeriod(int $tenantId, string $label, string $start, string $end): array
    {
        $this->insert('accounting_periods', ['tenant_id' => $tenantId, 'label' => $label, 'starts_on' => $start, 'ends_on' => $end, 'status' => 'open', 'created_at' => Clock::now(), 'updated_at' => Clock::now()]);
        return $this->find('accounting_periods', (int) $this->pdo->lastInsertId(), $tenantId);
    }

    private function requiredSettings(int $tenantId, array $keys): array
    {
        $settings = $this->one('SELECT * FROM finance_settings WHERE tenant_id = :tenant', ['tenant' => $tenantId]);
        foreach ($keys as $key) if (! $settings || empty($settings[$key])) throw new HttpException(422, 'La configuration comptable doit être complétée avant cette opération.');
        return $settings;
    }

    private function journalFor(int $tenantId, string $type): int
    {
        $row = $this->one('SELECT id FROM accounting_journals WHERE tenant_id = :tenant AND type = :type AND is_active = 1 ORDER BY id LIMIT 1', ['tenant' => $tenantId, 'type' => $type]);
        if (! $row) throw new HttpException(422, 'Le journal comptable correspondant doit être créé avant cette opération.');
        return (int) $row['id'];
    }

    private function entryLines(int $entryId): array { $statement = $this->pdo->prepare('SELECT * FROM accounting_entry_lines WHERE entry_id = :entry ORDER BY id'); $statement->execute(['entry' => $entryId]); return $statement->fetchAll(); }
    private function all(string $table, int $tenantId, string $order): array { $statement = $this->pdo->prepare("SELECT * FROM {$table} WHERE tenant_id = :tenant ORDER BY {$order}"); $statement->execute(['tenant' => $tenantId]); return $statement->fetchAll(); }
    private function find(string $table, int $id, int $tenantId): ?array { return $this->one("SELECT * FROM {$table} WHERE id = :id AND tenant_id = :tenant LIMIT 1", ['id' => $id, 'tenant' => $tenantId]); }
    private function one(string $sql, array $bindings): ?array { $statement = $this->pdo->prepare($sql); $statement->execute($bindings); $row = $statement->fetch(); return $row ?: null; }
    private function insert(string $table, array $data): void { $columns = array_keys($data); $sql = 'INSERT INTO '.$table.' ('.implode(',', $columns).') VALUES ('.implode(',', array_map(fn ($column) => ':'.$column, $columns)).')'; $this->pdo->prepare($sql)->execute($data); }

    private function deleteUnused(string $table, int $tenantId, int $id, string $usageTable, string $column): void
    {
        if (! $this->find($table, $id, $tenantId)) throw new HttpException(404, 'Ressource introuvable.');
        $statement = $this->pdo->prepare("SELECT 1 FROM {$usageTable} WHERE {$column} = :id LIMIT 1"); $statement->execute(['id' => $id]);
        if ($statement->fetchColumn()) throw new HttpException(422, 'Cette ressource est déjà utilisée et ne peut pas être supprimée.');
        $this->pdo->prepare("DELETE FROM {$table} WHERE id = :id AND tenant_id = :tenant")->execute(['id' => $id, 'tenant' => $tenantId]);
    }

    private function transaction(callable $callback): mixed
    {
        $owns = ! $this->pdo->inTransaction(); if ($owns) $this->pdo->beginTransaction();
        try { $result = $callback(); if ($owns) $this->pdo->commit(); return $result; }
        catch (Throwable $error) { if ($owns && $this->pdo->inTransaction()) $this->pdo->rollBack(); throw $error; }
    }

    private function date(string $value): string { $date = substr($value, 0, 10); if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) throw new HttpException(422, 'Date invalide.'); return $date; }
    private function presentAccount(array $row): array { return ['id' => (string) $row['id'], 'code' => $row['code'], 'label' => $row['label'], 'class' => (int) $row['class'], 'type' => $row['type'], 'isActive' => (bool) $row['is_active']]; }
    private function presentJournal(array $row): array { return ['id' => (string) $row['id'], 'code' => $row['code'], 'name' => $row['name'], 'type' => $row['type'], 'isActive' => (bool) $row['is_active']]; }
    private function presentPeriod(array $row): array { return ['id' => (string) $row['id'], 'label' => $row['label'], 'startsOn' => $row['starts_on'], 'endsOn' => $row['ends_on'], 'status' => $row['status'], 'closedAt' => $row['closed_at'] ?? null]; }
    private function presentSettings(array $row): array { return ['configured' => (bool) ($row['sales_account_id'] && $row['receivable_account_id'] && $row['bank_account_id'] && $row['wallet_account_id'] && $row['expense_account_id']), 'salesAccountId' => $row['sales_account_id'] ? (string) $row['sales_account_id'] : null, 'receivableAccountId' => $row['receivable_account_id'] ? (string) $row['receivable_account_id'] : null, 'taxAccountId' => $row['tax_account_id'] ? (string) $row['tax_account_id'] : null, 'bankAccountId' => $row['bank_account_id'] ? (string) $row['bank_account_id'] : null, 'walletAccountId' => $row['wallet_account_id'] ? (string) $row['wallet_account_id'] : null, 'expenseAccountId' => $row['expense_account_id'] ? (string) $row['expense_account_id'] : null]; }
    private function presentEntry(array $row): array { $lines = array_map(fn ($line) => ['id' => (string) $line['id'], 'accountId' => (string) $line['account_id'], 'label' => $line['label'], 'debit' => Money::output($line['debit']), 'credit' => Money::output($line['credit'])], $this->entryLines((int) $row['id'])); $debit = array_sum(array_map(fn ($line) => Money::cents($line['debit']), $lines)); return ['id' => (string) $row['id'], 'date' => $row['entry_date'], 'journalId' => (string) $row['journal_id'], 'periodId' => (string) $row['period_id'], 'reference' => $row['reference'], 'label' => $row['label'], 'status' => $row['status'], 'totalDebit' => Money::decimal($debit), 'totalCredit' => Money::decimal($debit), 'lines' => $lines, 'postedAt' => $row['posted_at']]; }
    private function presentInvoice(array $row): array { $statement = $this->pdo->prepare('SELECT * FROM invoice_lines WHERE invoice_id = :invoice ORDER BY id'); $statement->execute(['invoice' => $row['id']]); $lines = array_map(fn ($line) => ['id' => (string) $line['id'], 'description' => $line['description'], 'quantity' => Money::output($line['quantity']), 'unitPrice' => Money::output($line['unit_price']), 'taxRate' => (string) $line['tax_rate'], 'lineTotal' => Money::output($line['line_total'])], $statement->fetchAll()); return ['id' => (string) $row['id'], 'number' => $row['number'], 'clientName' => $row['client_name'], 'clientReference' => $row['client_reference'], 'campaignReference' => $row['campaign_reference'], 'issueDate' => $row['issue_date'], 'dueDate' => $row['due_date'], 'currency' => 'USD', 'subtotal' => Money::output($row['subtotal']), 'tax' => Money::output($row['tax']), 'total' => Money::output($row['total']), 'balance' => Money::output($row['balance']), 'status' => $row['status'], 'lines' => $lines]; }
    private function presentPayment(array $row): array { return ['id' => (string) $row['id'], 'invoiceId' => (string) $row['invoice_id'], 'walletAccountId' => $row['wallet_account_id'] ? (string) $row['wallet_account_id'] : null, 'amount' => Money::output($row['amount']), 'method' => $row['method'], 'reference' => $row['reference'], 'date' => $row['paid_at'], 'status' => $row['status']]; }
    private function presentWalletTransaction(array $row): array { return ['id' => (string) $row['id'], 'walletAccountId' => (string) $row['wallet_account_id'], 'type' => $row['type'], 'amount' => Money::output($row['amount']), 'source' => $row['source'], 'reference' => $row['reference'], 'status' => $row['status'], 'date' => $row['occurred_at'], 'createdAt' => $row['created_at']]; }
}
