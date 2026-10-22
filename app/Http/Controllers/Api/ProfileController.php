<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\ChangePasswordRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\TechnicianProfile;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    /** PUT /api/v1/profile  { name, phone, bio? }  (semua role; bio hanya dipakai teknisi) */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        DB::transaction(function () use ($user, $data) {
            $user->update([
                'name' => trim(strip_tags($data['name'])),
                'phone' => $data['phone'],
            ]);

            if ($user->isTechnician() && array_key_exists('bio', $data)) {
                $bio = trim(strip_tags((string) $data['bio']));

                TechnicianProfile::updateOrCreate(['user_id' => $user->id], ['bio' => $bio !== '' ? $bio : null]);
            }
        });

        return ApiResponse::success($this->payload($user), 'Profil diperbarui.');
    }

    /** POST /api/v1/profile/avatar  (multipart: avatar=file) */
    public function avatar(Request $request): JsonResponse
    {
        $request->validate([
            // mimes (bukan "image") agar SVG tidak lolos.
            'avatar' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ], [
            'avatar.required' => 'Pilih foto terlebih dahulu.',
            'avatar.file' => 'Berkas yang diunggah tidak valid.',
            'avatar.mimes' => 'Foto harus berformat JPG, PNG, atau WEBP.',
            'avatar.max' => 'Ukuran foto maksimal 2 MB.',
            'avatar.uploaded' => 'Foto gagal diunggah. Ukuran terlalu besar atau koneksi terputus.',
        ]);

        $user = $request->user();
        $old = $user->avatar;

        $path = $request->file('avatar')->store('avatars', 'public');
        $user->update(['avatar' => $path]);
        $this->deleteFile($old);

        return ApiResponse::success($this->payload($user), 'Foto profil diperbarui.');
    }

    /** DELETE /api/v1/profile/avatar */
    public function destroyAvatar(Request $request): JsonResponse
    {
        $user = $request->user();
        $old = $user->avatar;

        $user->update(['avatar' => null]);
        $this->deleteFile($old);

        return ApiResponse::success($this->payload($user), 'Foto profil dihapus.');
    }

    /** PUT /api/v1/profile/password  { current_password, password, password_confirmation } */
    public function password(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        if (! Hash::check($data['current_password'], $user->password)) {
            return ApiResponse::error(
                'Kata sandi saat ini salah.',
                422,
                ['current_password' => ['Kata sandi saat ini salah.']]
            );
        }

        $user->forceFill(['password' => $data['password']])->save(); // di-hash otomatis oleh cast

        // Keluarkan perangkat lain; perangkat ini tetap login.
        $currentId = $user->currentAccessToken()?->id;
        $others = $user->tokens();

        if ($currentId) {
            $others->where('id', '!=', $currentId);
        }

        $others->delete();

        return ApiResponse::success(null, 'Kata sandi berhasil diubah.');
    }

    private function payload(User $user): array
    {
        return (new UserResource($user->fresh()->load('technicianProfile')))->resolve();
    }

    /** URL penuh (gambar seeder) tidak pernah dihapus dari disk. */
    private function deleteFile(?string $path): void
    {
        if ($path && ! str_starts_with($path, 'http')) {
            Storage::disk('public')->delete($path);
        }
    }
}