@extends('admin.layouts.app')

@section('title', 'Profil Perusahaan')
@section('page_title', 'Profil Perusahaan')

@section('content')
<div class="card border-0 shadow-sm" style="max-width: 860px;">
    <div class="card-body">
        <p class="text-secondary">Data ini tampil di menu <strong>Tentang Kami</strong> pada aplikasi pelanggan.</p>

        <form method="POST" action="{{ route('admin.company.update') }}" novalidate>
            @csrf
            @method('PUT')

            <div class="row g-3">
                <div class="col-md-6">
                    <label for="name" class="form-label">Nama Perusahaan</label>
                    <input type="text" id="name" name="name" value="{{ old('name', $company->name ?? 'reaple.id') }}"
                           class="form-control @error('name') is-invalid @enderror" maxlength="100" required>
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" id="email" name="email" value="{{ old('email', $company->email ?? '') }}"
                           class="form-control @error('email') is-invalid @enderror">
                    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-12">
                    <label for="address" class="form-label">Alamat Kantor</label>
                    <textarea id="address" name="address" rows="2" maxlength="500"
                              class="form-control @error('address') is-invalid @enderror" required>{{ old('address', $company->address ?? '') }}</textarea>
                    @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-12">
                    <label for="description" class="form-label">Informasi Perusahaan</label>
                    <textarea id="description" name="description" rows="4" maxlength="2000"
                              class="form-control @error('description') is-invalid @enderror">{{ old('description', $company->description ?? '') }}</textarea>
                    @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                @foreach ([
                    'instagram' => ['Instagram', 'https://instagram.com/namaakun'],
                    'facebook' => ['Facebook', 'https://facebook.com/namahalaman'],
                    'tiktok' => ['TikTok', 'https://tiktok.com/@namaakun'],
                    'whatsapp' => ['WhatsApp', '081234567890 atau https://wa.me/6281234567890'],
                ] as $field => [$label, $placeholder])
                    <div class="col-md-6">
                        <label for="{{ $field }}" class="form-label">{{ $label }}</label>
                        <input type="text" id="{{ $field }}" name="{{ $field }}" value="{{ old($field, $company->{$field} ?? '') }}"
                               class="form-control @error($field) is-invalid @enderror" placeholder="{{ $placeholder }}">
                        @error($field)<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                @endforeach
            </div>

            <div class="mt-4">
                <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> Simpan</button>
            </div>
        </form>
    </div>
</div>
@endsection