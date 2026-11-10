<?php

namespace App\Enums;

enum Role: string
{
    case SuperAdmin = 'super_admin';
    case Owner = 'owner';
    case Member = 'member';
}
