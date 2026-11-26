<?php

namespace App\Platform\Storage\Domain;

enum ScanVerdict
{
    case Clean;
    case Infected;
    case Unavailable;
}
