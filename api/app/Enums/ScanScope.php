<?php

namespace App\Enums;

/** What a detection rule scans per call: a chunk of products, a chunk of voters, or the whole run once. */
enum ScanScope: string
{
    case Products = 'products';
    case Users = 'users';
    case Run = 'run';
}
