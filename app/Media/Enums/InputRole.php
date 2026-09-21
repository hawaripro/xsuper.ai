<?php

namespace App\Media\Enums;

/**
 * The meaning of an input, kept explicit so a reference image, a start frame, an
 * avatar photo, or a speech track are never collapsed into one anonymous field.
 * A capability input's `key` is its stable request identity; the role is its meaning.
 */
enum InputRole: string
{
    case Prompt = 'prompt';
    case ImageRef = 'image_ref';
    case InitFrame = 'init_frame';
    case EndFrame = 'end_frame';
    case AvatarPhoto = 'avatar_photo';
    case SpeechAudio = 'speech_audio';
    case ReferenceVideo = 'reference_video';
}
