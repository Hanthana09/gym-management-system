<?php

namespace App\Enum;

enum CheckInMethod: string
{
    case QR = 'qr';
    case MANUAL = 'manual';
    case FRONT_DESK = 'front_desk';
}
