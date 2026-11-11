<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BadgeTemplate extends Model
{
    public const SIZES = ['small', 'large'];

    public const TYPES = ['athlete', 'coach', 'official', 'support', 'media', 'volunteer', 'vip'];

    /**
     * Fields that can be switched on/off on the card. 'club' is the footer
     * (club name, or country when off); 'banner' is the coloured title bar.
     * back_* are for the back side.
     */
    public const FIELDS = ['banner', 'photo', 'qr', 'gender', 'category', 'edbf_id', 'club', 'areas', 'back_name', 'back_qr', 'back_areas'];

    public const MAX_AREAS = 9;

    public const DEFAULT_TYPES = [
        'athlete' => ['label' => 'ATHLETE', 'color' => '#0072BC'],
        'coach' => ['label' => 'COACH', 'color' => '#2E7D32'],
        'official' => ['label' => 'OFFICIAL', 'color' => '#6A1B9A'],
        'support' => ['label' => 'S', 'color' => '#333333'],
        'media' => ['label' => 'MEDIA', 'color' => '#EF6C00'],
        'volunteer' => ['label' => 'VOLUNTEER', 'color' => '#00897B'],
        'vip' => ['label' => 'VIP', 'color' => '#B8860B'],
    ];

    protected $fillable = [
        'event_id',
        'size',
        'banner_text',
        'banner_color',
        'footer_color',
        'logo',
        'background',
        'back_background',
        'back_text',
        'bleed_mm',
        'fields',
        'types',
        'areas',
    ];

    protected $casts = [
        'fields' => 'array',
        'types' => 'array',
        'areas' => 'array',
        'bleed_mm' => 'integer',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    /** Template for the event, or an unsaved one with defaults. */
    public static function forEvent(Event $event): self
    {
        return self::firstOrNew(['event_id' => $event->id], [
            'size' => 'small',
            'banner_color' => '#0072BC',
            'footer_color' => '#F3F1DC',
            'bleed_mm' => 0,
        ]);
    }

    /** Field flags with defaults filled in (all on). */
    public function fieldFlags(): array
    {
        $saved = is_array($this->fields) ? $this->fields : [];
        $out = [];
        foreach (self::FIELDS as $f) {
            $out[$f] = array_key_exists($f, $saved) ? (bool) $saved[$f] : true;
        }
        return $out;
    }

    /** Per-type label/colour with defaults filled in. */
    public function typeStyles(): array
    {
        $saved = is_array($this->types) ? $this->types : [];
        $out = [];
        foreach (self::DEFAULT_TYPES as $type => $def) {
            $s = $saved[$type] ?? [];
            $out[$type] = [
                'label' => isset($s['label']) && $s['label'] !== '' ? (string) $s['label'] : $def['label'],
                'color' => isset($s['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $s['color']) ? $s['color'] : $def['color'],
                'areas' => array_values(array_map('intval', is_array($s['areas'] ?? null) ? $s['areas'] : [])),
            ];
        }
        return $out;
    }

    /** Access areas, sorted by number: [['no' => 1, 'name' => '...'], ...]. */
    public function areaList(): array
    {
        $out = [];
        foreach (is_array($this->areas) ? $this->areas : [] as $a) {
            $no = (int) ($a['no'] ?? 0);
            $name = trim((string) ($a['name'] ?? ''));
            if ($no >= 1 && $no <= self::MAX_AREAS && $name !== '') {
                $out[$no] = ['no' => $no, 'name' => $name];
            }
        }
        ksort($out);
        return array_values($out);
    }

    public function toApi(Event $event): array
    {
        return [
            'event_id' => $event->id,
            'size' => $this->size ?: 'small',
            'banner_text' => $this->banner_text,
            'banner_text_default' => $event->name,
            'banner_color' => $this->banner_color ?: '#0072BC',
            'footer_color' => $this->footer_color ?: '#F3F1DC',
            'logo' => $this->logo,
            'background' => $this->background,
            'back_background' => $this->back_background,
            'back_text' => $this->back_text,
            'bleed_mm' => (int) ($this->bleed_mm ?? 0),
            'fields' => $this->fieldFlags(),
            'types' => $this->typeStyles(),
            'areas' => $this->areaList(),
        ];
    }
}
