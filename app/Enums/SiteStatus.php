<?php

namespace App\Enums;

enum SiteStatus: string
{
    case Pending = 'pending';
    case KeyGenerated = 'key_generated';
    case Installing = 'installing';
    case Installed = 'installed';
    case Failed = 'failed';
}
