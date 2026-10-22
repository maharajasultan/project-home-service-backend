<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyProfile;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class AboutController extends Controller
{
    /** GET /api/v1/about  (publik) */
    public function show(): JsonResponse
    {
        $company = CompanyProfile::query()->first();

        if (! $company) {
            return ApiResponse::error('Informasi perusahaan belum tersedia.', 404);
        }

        return ApiResponse::success([
            'name' => $company->name,
            'address' => $company->address,
            'description' => $company->description,
            'email' => $company->email,
            'instagram' => $company->instagram,
            'facebook' => $company->facebook,
            'tiktok' => $company->tiktok,
            'whatsapp' => $company->whatsapp,
            'map_url' => 'https://www.openstreetmap.org/search?query='.urlencode($company->address),
        ]);
    }
}