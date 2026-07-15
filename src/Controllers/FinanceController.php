<?php

namespace Onekana\Api\Controllers;

use Onekana\Api\Http\Request;
use Onekana\Api\Http\Response;
use Onekana\Api\Repositories\FinanceRepository;

final class FinanceController
{
    public function __construct(private readonly FinanceRepository $finance) {}

    public function accounts(Request $request): Response { return $this->data($this->finance->accounts($this->tenant($request))); }
    public function createAccount(Request $request): Response { return $this->data($this->finance->createAccount($this->tenant($request), $request->input()), 201); }
    public function importAccounts(Request $request): Response { return $this->data($this->finance->importAccounts($this->tenant($request), (array) $request->input('accounts', [])), 201); }
    public function deleteAccount(Request $request, int $id): Response { $this->finance->deleteAccount($this->tenant($request), $id); return Response::noContent(); }
    public function journals(Request $request): Response { return $this->data($this->finance->journals($this->tenant($request))); }
    public function createJournal(Request $request): Response { return $this->data($this->finance->createJournal($this->tenant($request), $request->input()), 201); }
    public function deleteJournal(Request $request, int $id): Response { $this->finance->deleteJournal($this->tenant($request), $id); return Response::noContent(); }
    public function periods(Request $request): Response { return $this->data($this->finance->periods($this->tenant($request))); }
    public function createPeriod(Request $request): Response { return $this->data($this->finance->createPeriod($this->tenant($request), $request->input()), 201); }
    public function closePeriod(Request $request, int $id): Response { return $this->data($this->finance->closePeriod($this->tenant($request), $id)); }
    public function entries(Request $request): Response { return $this->data($this->finance->entries($this->tenant($request))); }
    public function createEntry(Request $request): Response { return $this->data($this->finance->createEntry($this->tenant($request), $this->user($request), $request->input()), 201); }
    public function reverseEntry(Request $request, int $id): Response { return $this->data($this->finance->reverseEntry($this->tenant($request), $this->user($request), $id, trim((string) $request->input('reason', '')))); }
    public function trialBalance(Request $request): Response { return $this->data($this->finance->trialBalance($this->tenant($request))); }
    public function settings(Request $request): Response { return $this->data($this->finance->settings($this->tenant($request))); }
    public function saveSettings(Request $request): Response { return $this->data($this->finance->saveSettings($this->tenant($request), $request->input())); }
    public function invoices(Request $request): Response { return $this->data($this->finance->invoices($this->tenant($request))); }
    public function createInvoice(Request $request): Response { return $this->data($this->finance->createInvoice($this->tenant($request), $request->input()), 201); }
    public function issueInvoice(Request $request, int $id): Response { return $this->data($this->finance->issueInvoice($this->tenant($request), $this->user($request), $id)); }
    public function payments(Request $request): Response { return $this->data($this->finance->payments($this->tenant($request))); }
    public function createPayment(Request $request): Response { return $this->data($this->finance->createPayment($this->tenant($request), $this->user($request), $request->input()), 201); }
    public function walletAccounts(Request $request): Response { return $this->data($this->finance->walletAccounts($this->tenant($request))); }
    public function createWalletAccount(Request $request): Response { return $this->data($this->finance->createWalletAccount($this->tenant($request), $request->input()), 201); }
    public function walletTransactions(Request $request): Response { return $this->data($this->finance->walletTransactions($this->tenant($request))); }
    public function createWalletTransaction(Request $request): Response { return $this->data($this->finance->createWalletTransaction($this->tenant($request), $this->user($request), $request->input()), 201); }

    private function data(array $data, int $status = 200): Response { return Response::json(['data' => $data], $status); }
    private function tenant(Request $request): int { return (int) ($request->get('user')['tenant_id'] ?? 0); }
    private function user(Request $request): int { return (int) ($request->get('user')['id'] ?? 0); }
}
