<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CompanyProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CompanyProfileController extends Controller
{
    public function edit(): View
    {
        return view('admin.company.edit', ['company' => CompanyProfile::query()->first()]);
    }

    public function update(Request $request): RedirectResponse
    {
        // WhatsApp boleh diisi nomor (0812...) atau tautan; nomor diubah menjadi tautan wa.me.
        $request->merge(['whatsapp' => $this->whatsappUrl($request->input('whatsapp'))]);

        $link = ['nullable', 'string', 'max:255', 'url', 'regex:/^https?:\/\//i'];

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'address' => ['required', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:2000'],
            'email' => ['nullable', 'email', 'max:255'],
            'instagram' => $link,
            'facebook' => $link,
            'tiktok' => $link,
            'whatsapp' => $link,
        ], [
            'name.required' => 'Nama perusahaan wajib diisi.',
            'address.required' => 'Alamat kantor wajib diisi.',
            'email.email' => 'Format email tidak valid.',
            'instagram.url' => 'Tautan Instagram harus berupa URL lengkap (https://...).',
            'facebook.url' => 'Tautan Facebook harus berupa URL lengkap (https://...).',
            'tiktok.url' => 'Tautan TikTok harus berupa URL lengkap (https://...).',
            'whatsapp.url' => 'Isi nomor WhatsApp (contoh 081234567890) atau tautan wa.me.',
            '*.regex' => 'Tautan harus diawali http:// atau https://.',
        ]);

        $text = fn (?string $v) => ($v = trim(strip_tags((string) $v))) === '' ? null : $v;

        $company = CompanyProfile::query()->first() ?? new CompanyProfile();

        $company->fill([
            'name' => $text($data['name']),
            'address' => $text($data['address']),
            'description' => $text($data['description'] ?? null),
            'email' => $text($data['email'] ?? null),
            'instagram' => $text($data['instagram'] ?? null),
            'facebook' => $text($data['facebook'] ?? null),
            'tiktok' => $text($data['tiktok'] ?? null),
            'whatsapp' => $text($data['whatsapp'] ?? null),
        ])->save();

        return back()->with('success', 'Profil perusahaan disimpan. Perubahan langsung tampil di menu Tentang Kami aplikasi.');
    }

    private function whatsappUrl(mixed $value): ?string
    {
        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        if (preg_match('/^\+?[\d\s\-]{8,20}$/', $text)) {
            $digits = preg_replace('/\D/', '', $text);
            $digits = str_starts_with($digits, '0') ? '62'.substr($digits, 1) : $digits;

            return 'https://wa.me/'.$digits;
        }

        return $text;
    }
}