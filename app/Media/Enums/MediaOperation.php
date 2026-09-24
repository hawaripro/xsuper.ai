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
    case Model3dToModel3d = 'model3d_to_model3d';
    case VideoToVideo = 'video_to_video';
    case VideoToAudio = 'video_to_audio';
    case VideoToText = 'video_to_text';
    case AudioToAudio = 'audio_to_audio';
    case AudioToText = 'audio_to_text';
    case SpeechToText = 'speech_to_text';
    case SpeechToSpeech = 'speech_to_speech';
    case TextToAudio = 'text_to_audio';
    case ImageToText = 'image_to_text';
    case ImageToJson = 'image_to_json';
    case TextToJson = 'text_to_json';
    case TextToText = 'text_to_text';
    case Vision = 'vision';
    case LanguageModel = 'language_model';
    case StructuredData = 'structured_data';
    case Training = 'training';
    case Workflow = 'workflow';
    case Inference = 'inference';
    case RealtimeVideo = 'realtime_video';
}
