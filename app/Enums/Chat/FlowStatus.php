<?php

namespace App\Enums\Chat;

enum FlowStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
