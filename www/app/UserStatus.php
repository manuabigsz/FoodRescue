<?php

namespace App;

enum UserStatus: string
{
    case Active = 'active';
    case Blocked = 'blocked';
}
