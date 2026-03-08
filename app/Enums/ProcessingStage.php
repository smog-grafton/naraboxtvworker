<?php

namespace App\Enums;

enum ProcessingStage: string
{
    case Download = 'download';
    case Probe = 'probe';
    case TranscodeMp4 = 'transcode_mp4';
    case GenerateHls = 'generate_hls';
    case Upload = 'upload';
    case CallbackCdn = 'callback_cdn';
    case SyncPortal = 'sync_portal';
    case Cleanup = 'cleanup';
}
