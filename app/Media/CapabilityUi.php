<?php

namespace App\Media;

/**
 * Default UI metadata (label / help / control / placeholder) for a capability's inputs and
 * params, keyed by the stable input key / param name — declarative, never per-model. A
 * published revision may carry richer stored ui_metadata later; this supplies the sane
 * defaults so the frontend renders a labelled, correctly-typed control for whatever the
 * capability declares, without a second hardcoded ruleset.
 */
final class CapabilityUi
{
    /** input key => [label, control, placeholder, help] */
    private const INPUTS = [
        'prompt' => ['label' => 'Prompt', 'control' => 'textarea', 'placeholder' => 'Jelaskan subjek, komposisi, pencahayaan, dan gaya visual…', 'help' => null],
        'reference_image' => ['label' => 'Gambar referensi', 'control' => 'image', 'placeholder' => null, 'help' => 'Unggah gambar sebagai acuan komposisi atau gaya.'],
        'avatar_photo' => ['label' => 'Foto wajah', 'control' => 'image', 'placeholder' => null, 'help' => 'Gunakan foto wajah yang jelas dan Anda berhak gunakan.'],
        'speech_audio' => ['label' => 'Audio ucapan', 'control' => 'audio', 'placeholder' => null, 'help' => 'Gunakan ucapan bahasa Inggris yang jelas, tanpa musik latar.'],
        'image_ref' => ['label' => 'Gambar referensi', 'control' => 'image', 'placeholder' => null, 'help' => 'Gunakan satu objek yang terlihat jelas.'],
    ];

    /** param name => [label, help] (control derived from the param type) */
    private const PARAMS = [
        'size' => ['label' => 'Ukuran', 'help' => null],
        'aspect_ratio' => ['label' => 'Rasio aspek', 'help' => null],
        'duration' => ['label' => 'Durasi', 'help' => null],
        'resolution' => ['label' => 'Resolusi', 'help' => null],
        'quality' => ['label' => 'Kualitas', 'help' => null],
        'voice' => ['label' => 'Suara', 'help' => null],
        'speed' => ['label' => 'Kecepatan', 'help' => null],
        'seed' => ['label' => 'Seed (opsional)', 'help' => 'Kosongkan untuk hasil acak.'],
    ];

    /** @return array{inputs: array<string, mixed>, params: array<string, mixed>, order: string[]} */
    public static function describe(MediaCapability $capability): array
    {
        $inputs = [];
        $order = [];
        foreach ($capability->inputs as $input) {
            $meta = self::INPUTS[$input->key] ?? ['label' => self::humanize($input->key), 'control' => 'text', 'placeholder' => null, 'help' => null];
            $inputs[$input->key] = [
                'label' => $meta['label'],
                'help' => $meta['help'],
                'control' => $meta['control'],
                'placeholder' => $meta['placeholder'],
            ];
            $order[] = 'input:'.$input->key;
        }

        $params = [];
        foreach ($capability->params as $param) {
            $meta = self::PARAMS[$param->name] ?? ['label' => self::humanize($param->name), 'help' => null];
            $params[$param->name] = [
                'label' => $meta['label'],
                'help' => $meta['help'],
                'control' => self::control($param),
            ];
            $order[] = 'param:'.$param->name;
        }

        return ['inputs' => $inputs, 'params' => $params, 'order' => $order];
    }

    private static function control(CapabilityParam $param): string
    {
        return match ($param->type) {
            'enum' => 'select',
            'number' => $param->min !== null && $param->max !== null ? 'slider' : 'number',
            'integer' => 'number',
            'boolean' => 'toggle',
            default => 'text',
        };
    }

    private static function humanize(string $key): string
    {
        return ucfirst(str_replace(['_', '-'], ' ', $key));
    }
}
