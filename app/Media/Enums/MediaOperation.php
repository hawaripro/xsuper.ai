<?php

namespace App\Media\Enums;

/**
 * A model operation/mode. Capability is defined per model + operation, never per
 * model alone: the same model can support several operations with different inputs.
 * Listing an operation here does NOT mean any provider integration supports it yet.
 */
enum MediaOperation: string
{
    case TextToImage = 'text_to_image';
    case ImageEdit = 'image_edit';
    case TextToVideo = 'text_to_video';
    case ImageToVideo = 'image_to_video';
    case AudioToVideo = 'audio_to_video';
    case TalkingAvatar = 'talking_avatar';
    case TextToSpeech = 'text_to_speech';
    case Music = 'music';
    case TextTo3d = 'text_to_3d';
    case ImageTo3d = 'image_to_3d';
}
