<?php

namespace App\Media\Enums;

/** What a completed operation produces. */
enum OutputKind: string
{
    case Image = 'image';
    case Video = 'video';
    case Audio = 'audio';
    case Model3d = 'model3d';
    case Data = 'data';
    case File = 'file';
}
