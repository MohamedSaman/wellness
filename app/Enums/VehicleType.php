<?php

namespace App\Enums;

enum VehicleType: string
{
    case Motorcycle = 'motorcycle';
    case Scooter = 'scooter';
    case ThreeWheeler = 'three_wheeler';
    case Car = 'car';
    case Van = 'van';
    case Truck = 'truck';
    case Other = 'other';
}
