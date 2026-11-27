<?php

namespace Laravel\Ai\Enums;

enum StoreDocumentStatus: string
{
    case Completed = 'completed';
    case Pending = 'pending';
    case Failed = 'failed';
}
