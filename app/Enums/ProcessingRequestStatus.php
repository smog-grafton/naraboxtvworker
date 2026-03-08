<?php

namespace App\Enums;

enum ProcessingRequestStatus: string
{
    case Received = 'received';
    case Downloading = 'downloading';
    case Downloaded = 'downloaded';
    case Probing = 'probing';
    case Transcoding = 'transcoding';
    case Uploading = 'uploading';
    case Syncing = 'syncing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
