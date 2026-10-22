<?php

namespace App\Support;

class MediaUrl
{
    /**
     * Ubah path penyimpanan menjadi URL publik.
     * Jika sudah berupa URL penuh (http/https), kembalikan apa adanya.
     * Mengembalikan URL relatif /storage/... yang otomatis bekerja di:
     * - Web Admin (browser menggunakan host saat ini tanpa bergantung pada port 8000)
     * - Flutter (ApiConfig.resolveImageUrl otomatis menyematkan baseUrl/storageBaseUrl yang aktif)
     * - Laragon Apache (port 80), php artisan serve, Android emulator (10.0.2.2), maupun IP jaringan LAN
     */
    public static function resolve(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $cleanPath = ltrim(str_replace('\\', '/', $path), '/');
        if (str_starts_with($cleanPath, 'storage/')) {
            $cleanPath = substr($cleanPath, 8);
        }

        return '/storage/' . $cleanPath;
    }
}
