<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportingPeriodRequest;
use App\Http\Requests\ReverseFinancePaymentRequest;
use App\Http\Requests\ReverseFinancialRecordRequest;
use App\Http\Requests\StoreFinanceInvoiceRequest;
use App\Http\Requests\StoreFinancePaymentRequest;
use App\Http\Requests\StoreFinancialRecordRequest;
use App\Http\Requests\VoidFinanceInvoiceRequest;
use App\Models\AuditLog;
use App\Models\FinanceInvoice;
use App\Models\FinancePayment;
use App\Models\FinancialRecord;
use App\Services\KpiAnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinanceController extends Controller
{
    public function index(ReportingPeriodRequest $request, KpiAnalyticsService $analytics): JsonResponse
    {
        return response()->json($analytics->finance($request->period()));
    }

    public function store(StoreFinancialRecordRequest $request): JsonResponse
    {
        $data = $request->validated();
        $record = DB::transaction(function () use ($data, $request): FinancialRecord {
            $record = FinancialRecord::create([
                ...$data,
                'entry_kind' => 'normal',
                'source_type' => 'manual',
                'recorded_by' => $request->user()->id,
            ]);
            $this->audit($request, 'create', $record, $data);

            return $record;
        });

        return response()->json(['record' => $record->fresh()], 201);
    }

    public function reverseRecord(ReverseFinancialRecordRequest $request, FinancialRecord $record): JsonResponse
    {
        $data = $request->validated();

        $reversal = DB::transaction(function () use ($record, $data, $request): FinancialRecord {
            /** @var FinancialRecord $locked */
            $locked = FinancialRecord::query()->lockForUpdate()->findOrFail($record->id);

            if (CarbonImmutable::parse($data['recorded_on'])->lt($locked->recorded_on)) {
                throw ValidationException::withMessages(['recorded_on' => 'Tanggal reversal tidak boleh lebih awal dari transaksi asli.']);
            }
            if (($locked->entry_kind ?? 'normal') !== 'normal') {
                throw ValidationException::withMessages(['record' => 'Entri reversal tidak dapat direversal kembali.']);
            }
            if ($locked->reversals()->exists()) {
                throw ValidationException::withMessages(['record' => 'Transaksi ini sudah memiliki reversal.']);
            }
            if (in_array($locked->source_type, ['finance_invoice', 'integration_invoice'], true)) {
                throw ValidationException::withMessages(['record' => 'Pendapatan yang berasal dari invoice harus dikoreksi melalui proses void invoice.']);
            }

            $reversal = FinancialRecord::create([
                'recorded_on' => $data['recorded_on'],
                'type' => $locked->type,
                'entry_kind' => 'reversal',
                'reversal_of_id' => $locked->id,
                'category' => $locked->category,
                'department_code' => $locked->department_code,
                'amount' => $locked->amount,
                'description' => 'Reversal: '.$data['reason'],
                'reference' => $data['reference'] ?? ('REV-'.$locked->id.'-'.now()->format('YmdHisv')),
                'source_type' => 'reversal',
                'source_id' => $locked->id,
                'recorded_by' => $request->user()->id,
            ]);
            $this->audit($request, 'reverse', $reversal, [
                'original_record_id' => $locked->id,
                'reason' => $data['reason'],
                'amount' => $locked->amount,
            ]);

            return $reversal;
        });

        return response()->json(['record' => $record->fresh(), 'reversal' => $reversal], 201);
    }

    public function storeInvoice(StoreFinanceInvoiceRequest $request): JsonResponse
    {
        $data = $request->validated();
        $invoice = DB::transaction(function () use ($data, $request): FinanceInvoice {
            $invoice = FinanceInvoice::create([
                ...$data,
                'status' => 'issued',
                'created_by' => $request->user()->id,
            ]);

            $revenueRecord = FinancialRecord::create([
                'recorded_on' => $data['issued_on'],
                'type' => 'revenue',
                'entry_kind' => 'normal',
                'category' => $data['category'],
                'department_code' => $data['department_code'] ?? 'finance',
                'amount' => $data['amount'],
                'description' => $data['description'],
                'reference' => 'INV-'.$invoice->invoice_number,
                'source_type' => 'finance_invoice',
                'source_id' => $invoice->id,
                'recorded_by' => $request->user()->id,
            ]);
            $invoice->update(['revenue_record_id' => $revenueRecord->id]);

            $this->audit($request, 'create_invoice', $invoice, [
                ...$data,
                'revenue_record_id' => $revenueRecord->id,
            ]);

            return $invoice;
        });

        return response()->json(['invoice' => $invoice->fresh(['payments', 'revenueRecord'])], 201);
    }

    public function storePayment(StoreFinancePaymentRequest $request, FinanceInvoice $invoice): JsonResponse
    {
        $data = $request->validated();

        $payment = DB::transaction(function () use ($invoice, $data, $request): FinancePayment {
            $locked = FinanceInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if (CarbonImmutable::parse($data['paid_on'])->lt($locked->issued_on)) {
                throw ValidationException::withMessages(['paid_on' => 'Tanggal pembayaran tidak boleh lebih awal dari tanggal invoice.']);
            }
            if ($locked->status === 'void') {
                throw ValidationException::withMessages(['invoice' => 'Invoice yang sudah void tidak dapat menerima pembayaran.']);
            }

            $paid = (float) $locked->payments()->whereNull('reversed_at')->sum('amount');
            $outstanding = max(0, (float) $locked->amount - $paid);
            if ((float) $data['amount'] > $outstanding + 0.005) {
                throw ValidationException::withMessages(['amount' => 'Pembayaran melebihi saldo piutang invoice.']);
            }

            $payment = FinancePayment::create([
                'finance_invoice_id' => $locked->id,
                ...$data,
                'recorded_by' => $request->user()->id,
            ]);

            $remaining = max(0, $outstanding - (float) $data['amount']);
            $locked->update(['status' => $remaining <= 0.005 ? 'paid' : 'partially_paid']);
            $this->audit($request, 'record_payment', $payment, [
                ...$data,
                'invoice_id' => $locked->id,
                'remaining' => $remaining,
            ]);

            return $payment;
        });

        return response()->json([
            'payment' => $payment,
            'invoice' => $payment->invoice()->with('payments')->first(),
        ], 201);
    }

    public function reversePayment(ReverseFinancePaymentRequest $request, FinancePayment $payment): JsonResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($payment, $data, $request): void {
            // Keep lock ordering consistent with storePayment/voidInvoice: invoice first, then payment.
            // finance_invoice_id is immutable after payment creation, so the route-bound value is safe
            // to select the parent lock and is revalidated after the payment row is locked.
            $invoice = FinanceInvoice::query()->lockForUpdate()->findOrFail($payment->finance_invoice_id);
            /** @var FinancePayment $lockedPayment */
            $lockedPayment = FinancePayment::query()->lockForUpdate()->findOrFail($payment->id);
            if ((int) $lockedPayment->finance_invoice_id !== (int) $invoice->id) {
                throw ValidationException::withMessages(['payment' => 'Relasi pembayaran ke invoice berubah. Muat ulang data dan coba lagi.']);
            }
            if ($lockedPayment->reversed_at) {
                throw ValidationException::withMessages(['payment' => 'Pembayaran ini sudah direversal.']);
            }

            $lockedPayment->update([
                'reversed_at' => now(),
                'reversed_by' => $request->user()->id,
                'reversal_reason' => $data['reason'],
            ]);
            if ($invoice->status !== 'void') {
                $paid = (float) $invoice->payments()->whereNull('reversed_at')->sum('amount');
                $invoice->update([
                    'status' => $paid <= 0.005 ? 'issued' : ($paid + 0.005 >= (float) $invoice->amount ? 'paid' : 'partially_paid'),
                ]);
            }
            $this->audit($request, 'reverse_payment', $lockedPayment, $data);
        });

        return response()->json(['payment' => $payment->fresh(), 'invoice' => $payment->invoice()->with('payments')->first()]);
    }

    public function voidInvoice(VoidFinanceInvoiceRequest $request, FinanceInvoice $invoice): JsonResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($invoice, $data, $request): void {
            /** @var FinanceInvoice $locked */
            $locked = FinanceInvoice::query()->with('revenueRecord')->lockForUpdate()->findOrFail($invoice->id);

            if (CarbonImmutable::parse($data['voided_on'])->lt($locked->issued_on)) {
                throw ValidationException::withMessages(['voided_on' => 'Tanggal void tidak boleh lebih awal dari tanggal invoice.']);
            }
            if ($locked->status === 'void') {
                throw ValidationException::withMessages(['invoice' => 'Invoice ini sudah berstatus void.']);
            }
            if ($locked->payments()->whereNull('reversed_at')->exists()) {
                throw ValidationException::withMessages(['invoice' => 'Reverse seluruh pembayaran aktif sebelum melakukan void invoice.']);
            }

            $revenue = $locked->revenueRecord;
            if (! $revenue) {
                throw ValidationException::withMessages(['invoice' => 'Posting pendapatan invoice tidak ditemukan.']);
            }
            if ($revenue->reversals()->exists()) {
                throw ValidationException::withMessages(['invoice' => 'Posting pendapatan invoice sudah direversal.']);
            }

            FinancialRecord::create([
                'recorded_on' => $data['voided_on'],
                'type' => 'revenue',
                'entry_kind' => 'reversal',
                'reversal_of_id' => $revenue->id,
                'category' => $revenue->category,
                'department_code' => $revenue->department_code,
                'amount' => $revenue->amount,
                'description' => 'Void invoice '.$locked->invoice_number.': '.$data['reason'],
                'reference' => 'VOID-'.$locked->id.'-'.now()->format('YmdHis'),
                'source_type' => 'invoice_void',
                'source_id' => $locked->id,
                'recorded_by' => $request->user()->id,
            ]);

            $locked->update([
                'status' => 'void',
                'voided_at' => CarbonImmutable::parse($data['voided_on'])->endOfDay(),
                'voided_by' => $request->user()->id,
                'void_reason' => $data['reason'],
            ]);
            $this->audit($request, 'void_invoice', $locked, $data);
        });

        return response()->json(['invoice' => $invoice->fresh(['payments', 'revenueRecord'])]);
    }

    private function audit($request, string $action, $entity, array $changes): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => $entity::class,
            'entity_id' => $entity->id,
            'changes' => $changes,
            'ip_address' => $request->ip(),
        ]);
    }
}
