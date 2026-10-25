<?php

namespace App\Support;

use App\Enums\ProgressCategory;

class BimaProgressMapping
{
    public const SURVEY = [
        'Approval Survey-COMPLETE',
        'Site-Survey Manual-COMPLETE',
        'Site-Survey Manual-STARTWORK',
        'Survey Perangkat Metro dan Rak OLO-COMPLETE',
        'Survey-Ondesk Manual-CANCLWORK',
        'Survey-Ondesk Manual-STARTWORK',
        'Site Survey-STARTWORK',
        'Resource Allocation-STARTWORK',
        'Survey-Ondesk Manual-COMPLETE',
        'Populate ANUplink OLO-STARTWORK',
        'Populate ME Access Manual-STARTWORK',
        'Populate METRO NNI-STARTWORK',
        'PopulateANDownlinkPort Manual-STARTWORK',
        'PopulatePEPort-COMPLETE',
        'Entry VLAN and VCID Metro Manual-COMPLETE',
        'Entry VLAN and VCID Metro Manual-STARTWORK',
        'Populate Switch-STARTWORK',
        'Populate ME Access-STARTWORK',
        'Review LME-STARTWORK',
        'Review Order Bitstream Transport-COMPLETE',
        'Review Order Metro Manual-COMPLETE',
        'Review Order neuCentrIX-STARTWORK',
    ];

    public const INSTALASI = [
        'Activate Access Manual-STARTWORK',
        'Activate Cross Connect-STARTWORK',
        'Activate Global-COMPLETE',
        'Activate Metro Manual-COMPLETE',
        'Activate Metro Manual-STARTWORK',
        'Activate NCIX-STARTWORK',
        'Activate Service-STARTWORK',
        'Activation Power-STARTWORK',
        'CE Jumpering-STARTWORK',
        'Create MRTG Metro Manual-COMPLETE',
        'Create MRTG Metro Manual-STARTWORK',
        'Integrasi Access Port WDM End 2-STARTWORK',
        'Pickup NTE from SCM Manual-STARTWORK',
        'Pull Drop Cable Manual-STARTWORK',
        'Registration Suplychain-STARTWORK',
        'Activate Metro Bitstream Transport-STARTWORK',
        'Install NTE Manual-COMPLETE',
        'Activate Uplink OLT-STARTWORK',
        'Recover NTE-STARTWORK',
        'Create Dashboard MRTG Metro Manual-STARTWORK',
        'Pull Drop Cable Bitstream NNI-STARTWORK',
        'Pickup NTE from SCM Manual-CANCLWORK',
        'Activate Cross Connect-CANCLWORK',
        'Create Dashboard MRTG-STARTWORK',
        'Install NTE Manual-STARTWORK',
        'Integrasi Access Port WDM End 1-STARTWORK',
        'Create MRTG-STARTWORK',
        'Activation NTE-COMPLETE',
        'Pickup NTE from SCM-STARTWORK',
        'Activate Metro IP Transit-STARTWORK',
        'Activate Colocation-STARTWORK',
        'Activate Access-STARTWORK',
        'Activation NTE-STARTWORK',
        'Activate Pre-Cabling-STARTWORK',
        'Pull Drop Cable for Direct-COMPLETE',
        'Pull Drop Cable for Direct-STARTWORK',
        'Activate Metro Bitstream Transport-COMPLETE',
    ];

    public static function category(?string $statusOrder): ?ProgressCategory
    {
        $statusOrder = trim($statusOrder ?? '');

        return match (true) {
            in_array($statusOrder, self::SURVEY, true) => ProgressCategory::Survey,
            in_array($statusOrder, self::INSTALASI, true) => ProgressCategory::Instalasi,
            default => null,
        };
    }
}
