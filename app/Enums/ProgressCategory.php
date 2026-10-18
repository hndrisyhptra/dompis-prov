<?php

namespace App\Enums;

enum ProgressCategory: string
{
    case Close = 'CLOSE';
    case OgpBima = 'OGP BIMA';
    case ResubmitAo = 'RESUBMIT AO';
    case Survey = 'SURVEY';
    case Cancel = 'CANCEL';
    case Kendala = 'KENDALA';
    case Jt = 'JT';
    case Cndc = 'CNDC';
    case Perizinan = 'PERIZINAN';
    case KonfirmasiPic = 'KONFIRMASI PIC';
    case DeliveryMaterial = 'DELIVERY MATERIAL';
    case Hold = 'HOLD';
    case ButuhQe = 'BUTUH QE';
    case Instalasi = 'INSTALASI';
    case CloseOnDesk = 'CLOSE ON DESK';

    public function complete(): bool
    {
        return $this === self::Close;
    }

    public function terminal(): bool
    {
        return in_array($this, [self::Close, self::Cancel, self::CloseOnDesk], true);
    }

    public function operationalStatus(): string
    {
        return match ($this) {
            self::Close => 'FIELD_DONE',
            self::CloseOnDesk => 'PENDING_VALIDATION',
            self::Cancel => 'CANCELLED',
            self::Survey => 'SURVEY',
            self::Kendala => 'BLOCKED',
            default => 'IN_PROGRESS',
        };
    }
}
