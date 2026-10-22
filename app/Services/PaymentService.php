<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Exceptions\BusinessException;
use App\Models\FinanceTransaction;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\TechnicianSchedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentService
{
    public const METHOD_LABELS = [
        'indomaret' => 'Indomaret',
        'alfamart' => 'Alfamart',
        'bca_va' => 'BCA Virtual Account',
        'echannel' => 'Mandiri Bill Payment',
        'bri_va' => 'BRI Virtual Account',
        'bni_va' => 'BNI Virtual Account',
        'permata_va' => 'Permata Virtual Account',
    ];

    private const FAILED_STATUSES = ['expire', 'cancel', 'deny', 'failure'];

    public function __construct(
        private readonly MidtransService $midtrans,
        private readonly OrderService $orders,
    ) {}

    /**
     * Buat (atau ambil kembali) transaksi Snap untuk pesanan.
     *
     * @throws BusinessException
     */
    public function pay(Order $order): Payment
    {
        $this->orders->expireOverdue();

        return DB::transaction(function () use ($order) {
            $locked = Order::with(['items', 'user'])->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== OrderStatus::Pending) {
                throw new BusinessException(
                    'Pesanan ini tidak dapat dibayar. Mungkin sudah dibayar, dibatalkan, atau melewati batas waktu.'
                );
            }

            $remaining = (int) now()->diffInSeconds($locked->expired_at, false);

            if ($remaining <= 0) {
                throw new BusinessException('Batas waktu pembayaran telah habis.');
            }

            $existing = Payment::where('order_id', $locked->id)->latest('id')->first();

            if ($existing) {
                if ($existing->status === 'pending' && $existing->snap_token) {
                    return $existing;
                }

                throw new BusinessException('Transaksi pembayaran pesanan ini bermasalah. Hubungi admin.');
            }

            $snap = $this->midtrans->createSnapTransaction($this->buildParams($locked, $remaining));

            return Payment::create([
                'order_id' => $locked->id,
                'midtrans_order_id' => $locked->order_code,
                'status' => 'pending',
                'gross_amount' => $locked->total,
                'snap_token' => $snap['token'],
                'redirect_url' => $snap['redirect_url'],
            ]);
        });
    }

    /**
     * Buat transaksi pembayaran langsung (Core API) berdasarkan metode yang dipilih,
     * seperti Indomaret (menghasilkan payment_code untuk Midtrans Simulator) atau Bank VA.
     *
     * @throws BusinessException
     */
    public function payWithMethod(Order $order, string $method): array
    {
        $this->orders->expireOverdue();

        return DB::transaction(function () use ($order, $method) {
            $locked = Order::with(['items', 'user'])->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== OrderStatus::Pending) {
                throw new BusinessException(
                    'Pesanan ini tidak dapat dibayar. Mungkin sudah dibayar, dibatalkan, atau melewati batas waktu.'
                );
            }

            $remaining = max(0, (int) now()->diffInSeconds($locked->expired_at, false));

            if ($remaining <= 0) {
                throw new BusinessException('Batas waktu pembayaran telah habis.');
            }

            $existing = Payment::where('order_id', $locked->id)->latest('id')->first();

            if ($existing && $existing->status === 'pending' && $existing->method === $method && !empty($existing->payload)) {
                return $this->formatInstruction($locked, $existing->method, (array) $existing->payload, $remaining);
            }

            $chargeParams = $this->buildChargeParams($locked, $method);

            try {
                $chargeResult = (array) json_decode(json_encode(\Midtrans\CoreApi::charge($chargeParams)), true);
            } catch (\Throwable $e) {
                Log::error('Midtrans CoreApi Charge failed', ['method' => $method, 'order' => $locked->order_code, 'error' => $e->getMessage()]);
                throw new BusinessException('Gagal memproses metode pembayaran ini: ' . $e->getMessage(), 502);
            }

            $payment = Payment::updateOrCreate(
                ['order_id' => $locked->id],
                [
                    'midtrans_order_id' => $locked->order_code,
                    'method' => $method,
                    'status' => 'pending',
                    'gross_amount' => $locked->total,
                    'payload' => $chargeResult,
                ]
            );

            return $this->formatInstruction($locked, $method, $chargeResult, $remaining);
        });
    }

    private function buildChargeParams(Order $order, string $method): array
    {
        $base = [
            'transaction_details' => [
                'order_id' => $order->order_code,
                'gross_amount' => (int) $order->total,
            ],
            'customer_details' => [
                'first_name' => Str::limit($order->user->name, 100, ''),
                'email' => $order->user->email,
                'phone' => $order->phone_wa,
            ],
        ];

        return match ($method) {
            'indomaret' => array_merge($base, [
                'payment_type' => 'cstore',
                'cstore' => [
                    'store' => 'Indomaret',
                    'message' => 'Pembayaran Pesanan Reaple',
                ],
            ]),
            'alfamart' => array_merge($base, [
                'payment_type' => 'cstore',
                'cstore' => [
                    'store' => 'Alfamart',
                    'message' => 'Pembayaran Pesanan Reaple',
                ],
            ]),
            'bca_va' => array_merge($base, [
                'payment_type' => 'bank_transfer',
                'bank_transfer' => ['bank' => 'bca'],
            ]),
            'bri_va' => array_merge($base, [
                'payment_type' => 'bank_transfer',
                'bank_transfer' => ['bank' => 'bri'],
            ]),
            'bni_va' => array_merge($base, [
                'payment_type' => 'bank_transfer',
                'bank_transfer' => ['bank' => 'bni'],
            ]),
            'echannel' => array_merge($base, [
                'payment_type' => 'echannel',
                'echannel' => [
                    'bill_info1' => 'Payment For:',
                    'bill_info2' => 'Reaple Service',
                ],
            ]),
            'permata_va' => array_merge($base, [
                'payment_type' => 'permata',
            ]),
            default => array_merge($base, [
                'payment_type' => 'bank_transfer',
                'bank_transfer' => ['bank' => 'bca'],
            ]),
        };
    }

    private function formatInstruction(Order $order, string $method, array $payload, int $remaining): array
    {
        return [
            'order_id' => $order->id,
            'order_code' => $order->order_code,
            'method' => $method,
            'amount' => (int) $order->total,
            'seconds_remaining' => $remaining,
            'va_number' => $payload['va_numbers'][0]['va_number'] ?? ($payload['permata_va_number'] ?? null),
            'bill_key' => $payload['bill_key'] ?? null,
            'biller_code' => $payload['biller_code'] ?? null,
            'payment_code' => $payload['payment_code'] ?? null,
            'store' => $payload['store'] ?? ($method === 'indomaret' ? 'indomaret' : ($method === 'alfamart' ? 'alfamart' : null)),
        ];
    }

    /** Tanya status ke Midtrans lalu proses seperti notifikasi. */
    public function sync(Order $order): void
    {
        $this->orders->expireOverdue();

        $payment = Payment::where('order_id', $order->id)->latest('id')->first();

        if (! $payment) {
            return;
        }

        $data = $this->midtrans->fetchStatus($payment->midtrans_order_id);

        if ($data !== null) {
            $this->handleNotification($data);
        }
    }

    /**
     * Proses notifikasi Midtrans (webhook atau hasil sync). Aman dipanggil berulang.
     * Pemanggil webhook WAJIB memverifikasi signature lebih dulu.
     */
    public function handleNotification(array $n): ?Payment
    {
        $midtransOrderId = (string) ($n['order_id'] ?? '');
        $status = $this->normalizeStatus($n);

        return DB::transaction(function () use ($n, $midtransOrderId, $status) {
            $payment = Payment::where('midtrans_order_id', $midtransOrderId)->lockForUpdate()->first();

            if (! $payment) {
                Log::info('Midtrans: order_id tidak dikenal, diabaikan.', ['order_id' => $midtransOrderId]);
                return null;
            }

            $amount = (int) round((float) ($n['gross_amount'] ?? 0));

            if ($amount !== (int) $payment->gross_amount) {
                Log::critical('Midtrans: nominal tidak cocok.', [
                    'order_id' => $midtransOrderId,
                    'expected' => $payment->gross_amount,
                    'received' => $amount,
                ]);
                return null;
            }

            if (
                $payment->status === 'settlement'
                && in_array($status, ['pending', ...self::FAILED_STATUSES], true)
            ) {
                return $payment;
            }

            $payment->fill([
                'status' => $status,
                'method' => $this->resolveMethod($n) ?? $payment->method,
                'payload' => Arr::except($n, ['signature_key']),
            ])->save();

            if ($status === 'settlement') {
                $this->markPaid($payment);
            } elseif (in_array($status, self::FAILED_STATUSES, true)) {
                $this->orders->release($payment->order_id);
            }

            return $payment->refresh();
        });
    }

    public function methodLabel(?string $method): string
    {
        return self::METHOD_LABELS[$method] ?? ($method ?: 'Midtrans');
    }

    private function markPaid(Payment $payment): void
    {
        $order = Order::with('items.product')->whereKey($payment->order_id)->lockForUpdate()->first();

        if (! $order) {
            return;
        }

        if ($order->status === OrderStatus::Failed && ! $this->revive($order)) {
            Log::critical('Pembayaran masuk untuk pesanan gagal yang tidak bisa dihidupkan. PERLU REFUND MANUAL.', [
                'order_code' => $order->order_code,
                'amount' => $order->total,
            ]);
            return;
        }

        if ($order->status !== OrderStatus::Pending) {
            return;
        }

        $now = now();

        $order->update(['status' => OrderStatus::Paid, 'paid_at' => $now]);
        $payment->update(['paid_at' => $now]);

        foreach ($order->items as $item) {
            if ($item->product_variant_id && $item->product?->type === ProductType::Sparepart) {
                StockMovement::create([
                    'product_variant_id' => $item->product_variant_id,
                    'order_id' => $order->id,
                    'type' => 'out',
                    'qty' => $item->qty,
                    'note' => "Terjual - pesanan {$order->order_code}",
                ]);
            }
        }

        FinanceTransaction::create([
            'order_id' => $order->id,
            'type' => 'in',
            'category' => 'penjualan',
            'amount' => $order->total,
            'description' => "Pembayaran pesanan {$order->order_code} via " . $this->methodLabel($payment->method),
            'transaction_date' => $now->toDateString(),
        ]);
    }

    private function revive(Order $order): bool
    {
        $at = $order->scheduled_at;

        if (! $order->technician_id || ! $at || $at->isPast()) {
            return false;
        }

        $variantIds = $order->items->pluck('product_variant_id')->filter()->all();
        $variants = ProductVariant::whereIn('id', $variantIds)->lockForUpdate()->get()->keyBy('id');

        $spareparts = $order->items->filter(
            fn($item) => $item->product_variant_id && $item->product?->type === ProductType::Sparepart
        );

        foreach ($spareparts as $item) {
            $variant = $variants->get($item->product_variant_id);

            if (! $variant || $variant->stock < $item->qty) {
                return false;
            }
        }

        try {
            TechnicianSchedule::create([
                'technician_id' => $order->technician_id,
                'order_id' => $order->id,
                'date' => $at->toDateString(),
                'time_slot' => $at->format('H:i:s'),
            ]);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return false;
            }
            throw $e;
        }

        foreach ($spareparts as $item) {
            $variants->get($item->product_variant_id)->decrement('stock', $item->qty);
        }

        $order->update(['status' => OrderStatus::Pending]);

        return true;
    }

    private function buildParams(Order $order, int $remainingSeconds): array
    {
        $items = $order->items->map(fn($item) => [
            'id' => 'ITEM-' . $item->id,
            'price' => (int) $item->price + (int) $item->base_fee,
            'quantity' => (int) $item->qty,
            'name' => Str::limit(trim($item->product_name . ' ' . $item->variant_label), 50, ''),
        ])->values()->all();

        return [
            'transaction_details' => [
                'order_id' => $order->order_code,
                'gross_amount' => (int) $order->total,
            ],
            'item_details' => $items,
            'customer_details' => [
                'first_name' => Str::limit($order->user->name, 100, ''),
                'email' => $order->user->email,
                'phone' => $order->phone_wa,
            ],
            'enabled_payments' => config('midtrans.enabled_payments'),
            'expiry' => [
                'unit' => 'minutes',
                'duration' => max(1, intdiv($remainingSeconds, 60)),
            ],
            'callbacks' => ['finish' => url('/payment/finish')],
            'custom_field1' => $order->order_code,
        ];
    }

    private function normalizeStatus(array $n): string
    {
        $status = (string) ($n['transaction_status'] ?? '');

        if ($status === 'capture') {
            return ($n['fraud_status'] ?? 'accept') === 'challenge' ? 'pending' : 'settlement';
        }

        return $status;
    }

    private function resolveMethod(array $n): ?string
    {
        $type = $n['payment_type'] ?? null;

        return match (true) {
            $type === null => null,
            $type === 'bank_transfer' => isset($n['va_numbers'][0]['bank'])
                ? strtolower($n['va_numbers'][0]['bank']) . '_va'
                : (isset($n['permata_va_number']) ? 'permata_va' : 'bank_transfer'),
            $type === 'cstore' => strtolower((string) ($n['store'] ?? 'cstore')),
            default => (string) $type,
        };
    }
}