<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

class WorkOrderUpload
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    public static function limitBytes(): int
    {
        return (int) min(self::MAX_BYTES, UploadedFile::getMaxFilesize());
    }

    public static function limitLabel(): string
    {
        return number_format(self::limitBytes() / 1024 / 1024, 1, ',', '.').' MB';
    }

    public static function errorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File melebihi batas upload server ('.self::limitLabel().'). Pilih file yang lebih kecil atau hubungi administrator.',
            UPLOAD_ERR_PARTIAL => 'Upload file terputus sebelum selesai. Periksa koneksi lalu pilih dan upload ulang file XLSX.',
            UPLOAD_ERR_NO_FILE => 'Pilih file XLSX hasil export BIMA terlebih dahulu.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'Server tidak dapat menyimpan file sementara. Hubungi administrator untuk memeriksa penyimpanan upload.',
            default => 'Server menolak upload file. Pilih ulang file XLSX dan coba lagi. Hubungi administrator jika masih gagal.',
        };
    }
}
