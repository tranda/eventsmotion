<?php

namespace App\Services\Badges;

use App\Models\BadgeTemplate;
use Illuminate\Support\Facades\File;
use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * Renders two-sided badges to a print-ready PDF. Default: pages alternate
 * front, back, front, back... so the file prints duplex. "Spread": front and
 * back side by side on one page (like the ECCC 2025 A5 sheets) to fold or
 * cut. Each side = card size plus bleed on every side. Both sizes portrait:
 *   small  54 x  85.6 mm (ID-1, like EDBF Nationals 2026)
 *   large 100 x 145   mm
 *
 * The layout is designed once on the small card's 54 x 85.6 grid and scaled
 * to the page (x by width, y and fonts by height), so both sizes look alike.
 * Square things (QR) use the smaller factor.
 */
class BadgeRenderer
{
    public const SIZES = [
        'small' => [54.0, 85.6],
        'large' => [100.0, 145.0],
    ];

    private const GRID_W = 54.0;
    private const GRID_H = 85.6;

    private const GENDER_COLORS = ['FEMALE' => '#E30613', 'MALE' => '#009640'];
    private const CATEGORY_COLOR = '#B3121A';
    private const AREA_ON = '#0D2B6B';
    private const AREA_OFF = '#C9CEE6';
    private const FONT = 'dejavusanscondensed';

    private float $w;
    private float $h;
    private float $b;
    private float $sx;
    private float $sy;
    private float $fs;
    private string $tmp;
    private array $fields;
    private array $types;
    private array $areas;
    private float $ox = 0; // x offset of the side being drawn (spread mode)
    private ?Mpdf $mpdf = null; // for measuring text widths

    public function __construct(
        private BadgeTemplate $template,
        private string $eventName,
        private string $size,
        private bool $spread = false,
        private bool $trimLines = false, // previews: dashed line where the printer cuts
        private bool $guide = false,     // designer template: placeholders, no artwork, bleed/trim/safe marks
    ) {
        [$this->w, $this->h] = self::SIZES[$size] ?? self::SIZES['small'];
        $this->b = max(0, min(5, (int) $template->bleed_mm));
        $this->sx = $this->w / self::GRID_W;
        $this->sy = $this->h / self::GRID_H;
        $this->fs = min($this->sx, $this->sy);
        $this->fields = $template->fieldFlags();
        $this->types = $template->typeStyles();
        $this->areas = $template->areaList();
    }

    /** @param array $entries from BadgeService::entries() */
    public function pdf(array $entries): string
    {
        $this->tmp = storage_path('app/mpdf/badges_' . bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($this->tmp);

        try {
            $mpdf = new Mpdf([
                'mode' => 'utf-8',
                'format' => [($this->spread ? 2 : 1) * ($this->w + 2 * $this->b), $this->h + 2 * $this->b],
                'orientation' => 'P',
                'tempDir' => $this->tmp,
                'margin_left' => 0,
                'margin_right' => 0,
                'margin_top' => 0,
                'margin_bottom' => 0,
                'margin_header' => 0,
                'margin_footer' => 0,
                'default_font' => self::FONT,
                'autoPageBreak' => false,
            ]);
            $this->mpdf = $mpdf;
            $mpdf->SetTitle('Badges - ' . $this->eventName);
            $mpdf->WriteHTML('body { font-family: ' . self::FONT . '; margin: 0; padding: 0; }', HTMLParserMode::HEADER_CSS);

            if (empty($entries)) {
                $mpdf->WriteHTML('<div style="padding:5mm">No badges.</div>', HTMLParserMode::HTML_BODY);
            }
            foreach (array_values($entries) as $i => $e) {
                if ($i > 0) {
                    $mpdf->AddPage();
                }
                $this->ox = 0;
                $mpdf->WriteHTML($this->guideBase() . $this->front($e) . $this->trimLine() . $this->guideLines('FRONT'), HTMLParserMode::HTML_BODY);
                if ($this->spread) {
                    $this->ox = $this->w + 2 * $this->b;
                } else {
                    $mpdf->AddPage();
                }
                $mpdf->WriteHTML($this->guideBase() . $this->back($e) . $this->trimLine() . $this->guideLines('BACK'), HTMLParserMode::HTML_BODY);
            }

            return $mpdf->Output('', 'S');
        } finally {
            File::deleteDirectory($this->tmp);
        }
    }

    /** Dashed rectangle on the trim edge of the current side (previews with bleed only). */
    private function trimLine(): string
    {
        if (!$this->trimLines || $this->b <= 0) {
            return '';
        }
        return sprintf(
            '<div style="position:absolute; left:%.2fmm; top:%.2fmm; width:%.2fmm; height:%.2fmm; border:0.25mm dashed #E6007E;"></div>',
            $this->ox + $this->b, $this->b, $this->w, $this->h
        );
    }

    // ------------------------------------------------------- designer guide

    /** Guide: bleed area tinted pink, card area white. */
    private function guideBase(): string
    {
        if (!$this->guide) {
            return '';
        }
        return sprintf('<div style="position:absolute; left:%.2fmm; top:0; width:%.2fmm; height:%.2fmm; background-color:#FCE4EF;"></div>',
                $this->ox, $this->w + 2 * $this->b, $this->h + 2 * $this->b)
            . sprintf('<div style="position:absolute; left:%.2fmm; top:%.2fmm; width:%.2fmm; height:%.2fmm; background-color:#FFFFFF;"></div>',
                $this->ox + $this->b, $this->b, $this->w, $this->h);
    }

    /** Guide: trim line (pink dashed), safe area 2 mm inside (blue dotted), side label. */
    private function guideLines(string $side): string
    {
        if (!$this->guide) {
            return '';
        }
        $safe = 2.0;
        $html = sprintf('<div style="position:absolute; left:%.2fmm; top:%.2fmm; width:%.2fmm; height:%.2fmm; border:0.25mm dashed #E6007E;"></div>',
            $this->ox + $this->b, $this->b, $this->w, $this->h);
        $html .= sprintf('<div style="position:absolute; left:%.2fmm; top:%.2fmm; width:%.2fmm; height:%.2fmm; border:0.2mm dotted #1E88E5;"></div>',
            $this->ox + $this->b + $safe, $this->b + $safe, $this->w - 2 * $safe, $this->h - 2 * $safe);
        $label = $side . ' · ' . rtrim(rtrim(number_format($this->w, 1), '0'), '.') . ' × ' . rtrim(rtrim(number_format($this->h, 1), '0'), '.') . ' mm'
            . ($this->b > 0 ? ' + ' . $this->b . ' mm bleed' : '');
        // Label in the top bleed strip (outside the card) when there is room.
        if ($this->b >= 2) {
            $html .= $this->abs(0, -$this->b + 0.3, $this->w, $this->b - 0.6,
                $this->cell($label, 5, $this->b - 0.6, '#E6007E', true, 'center', 0, null, $this->w - 2));
        }
        return $html;
    }

    /** Guide: dashed box with a label (photo, QR, logo). */
    private function placeholder(float $rx, float $ry, float $rw, float $rh, string $label): string
    {
        $dims = round($rw, 1) . ' × ' . round($rh, 1) . ' mm';
        return $this->abs($rx, $ry, $rw, $rh,
            '<div style="font-size:' . max(4, min(8, $rw * 0.9)) . 'pt; color:#555555; text-align:center; font-weight:bold; padding-top:' . max(0.5, $rh / 2 - 3) . 'mm;">'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '<br /><span style="font-weight:normal; font-size:' . max(3.5, min(6, $rw * 0.6)) . 'pt;">' . $dims . '</span></div>',
            'background-color:#ECEFF1; border:0.25mm dashed #607D8B;');
    }

    // ---------------------------------------------------------------- layouts

    private function front(array $e): string
    {
        if (($this->template->layout ?? 'classic') === 'eurocup') {
            return $this->frontEuroCup($e);
        }
        $html = $this->fullImage($this->template->background);
        $html .= $this->logo(30, 1.5, 21.5, 8.5);
        if ($this->fields['banner']) {
            $html .= $this->band(11, 10, $this->bannerColor(), $this->bannerText(), 15, '#FFFFFF');
        }
        if ($this->fields['photo']) {
            $html .= $this->photo($e, 2, 26.5, 28.5, 38);
        }
        // Right column (x 32 → card edge): QR, ribbon, areas, category and ID
        // all centred on the ribbon's centre line.
        $colX = 32;
        $colW = self::GRID_W - $colX;
        if ($e['type'] === 'athlete' && $this->fields['qr']) {
            $qs = 13.5;
            $html .= $this->qr($e, $colX + ($colW - $qs * $this->fs / $this->sx) / 2, 26.5, $qs);
        }
        $html .= $this->typeTag($e, $colX, 41.5, 5, 9);
        $html .= $this->areaRow($e, $colX, 47.5, $colW);

        if ($this->fields['category'] && $e['category'] !== '') {
            $html .= $this->text($colX, 56.5, $colW, 4.5, mb_strtoupper($e['category']), 8.5, self::CATEGORY_COLOR, true, 'center');
        }
        if ($this->fields['edbf_id'] && $e['edbf_id'] !== '') {
            $html .= $this->text($colX, 61, $colW, 4, $e['edbf_id'], 8, '#000000', true, 'center');
        }
        $html .= $this->text(2.5, 67.5, 49, 3.8, mb_strtoupper($e['first_name']), 7, '#000000', false);
        $html .= $this->text(2.5, 71.2, 49, 5, mb_strtoupper($e['last_name']), 10, '#000000', true);
        $html .= $this->footer($e, 78.6, 7, 9);

        return $html;
    }

    /**
     * Euro Cup 2023 style front. The artwork carries the header (dates,
     * event title) and the bottom strip (logos); we draw photo, QR, the
     * category ribbon with gender under it, big names, accessible areas and
     * the centred club. Text colour = the template's banner colour.
     */
    private function frontEuroCup(array $e): string
    {
        $ink = $this->bannerColor();
        $html = $this->fullImage($this->template->background);

        if ($this->fields['photo']) {
            $html .= $this->photo($e, 2.5, 31.5, 20, 25);
        }

        // Right column x 33 → 51.4, everything centred on it.
        $colX = 33;
        $colW = 18.4;
        $isAthlete = $e['type'] === 'athlete';
        if ($isAthlete && $this->fields['qr']) {
            $qs = 16;
            $html .= $this->qr($e, $colX + ($colW - $qs * $this->fs / $this->sx) / 2, 31.5, $qs);
        }

        // Ribbon: athlete category (U24, SENIOR A…), else the badge type label.
        $style = $this->types[$e['type']] ?? ['label' => strtoupper($e['type']), 'color' => $ink];
        $label = $isAthlete
            ? ($this->fields['category'] && $e['category'] !== '' ? mb_strtoupper($e['category']) : $style['label'])
            : $style['label'];
        $ribbonColor = $isAthlete ? $ink : $style['color'];
        $rw = $colW * $this->sx;
        $rh = 5.6 * $this->sy;
        $html .= $this->abs($colX * $this->sx, 50.8 * $this->sy, $rw, $rh,
            $this->cell($label, 12 * $this->fs, $rh, '#FFFFFF', true, 'center', 1, null, $rw),
            'background-color:' . $ribbonColor . ';');

        if ($isAthlete && $this->fields['gender'] && $e['gender']) {
            $html .= $this->text($colX, 56.7, $colW, 3, $e['gender'], 8, $ink, true, 'center');
        }
        $html .= $this->areaRow($e, $colX, 60, $colW);

        // Names: first (and middle) names, then surname, large and in blue.
        $html .= $this->text(2.5, 59.2, 29.5, 4, $e['first_name'], 13, $ink, false);
        $html .= $this->text(2.5, 63.2, 29.5, 4, $e['last_name'], 13, $ink, true);
        if ($this->fields['edbf_id'] && $e['edbf_id'] !== '') {
            $html .= $this->text(2.5, 67.3, 29.5, 2.6, 'ID ' . $e['edbf_id'], 6.5, $ink, false);
        }

        $club = $this->fields['club'] ? ($e['club'] ?: $e['country']) : ($e['country'] ?: $e['club']);
        $html .= $this->text(2.5, 70, 49, 4.5, mb_strtoupper((string) $club), 12, $ink, true, 'center');

        return $html;
    }

    /**
     * Back side. With uploaded back artwork only the optional name / QR / text
     * are drawn over it; without artwork it repeats the logo, banner and footer.
     */
    private function back(array $e): string
    {
        // Designer guide shows the "with artwork" back: only the overlaid data.
        $artwork = $this->guide || $this->assetPath($this->template->back_background) !== null;
        $html = $this->fullImage($this->template->back_background);

        if (!$artwork) {
            $html .= $this->logo(30, 1.5, 21.5, 8.5);
            if ($this->fields['banner']) {
                $html .= $this->band(11, 10, $this->bannerColor(), $this->bannerText(), 15, '#FFFFFF');
            }
            $html .= $this->footer($e, 78.6, 7, 9);
        }

        // Name block, with the athlete's QR beside it (top right) so the lower
        // half stays free for the area legend and free text.
        $y = 23;
        $withQr = $e['type'] === 'athlete' && $this->fields['back_qr'] && is_callable($e['qr']);
        if ($withQr) {
            $qs = 16;
            $html .= $this->qr($e, self::GRID_W - 3 - $qs * $this->fs / $this->sx, $y, $qs);
        }
        $nx = 3;
        $nw = $withQr ? 31 : 48;
        $align = $withQr ? 'left' : 'center';
        if ($this->fields['back_name']) {
            $html .= $this->text($nx, $y, $nw, 5.5, mb_strtoupper($e['last_name']), 11, $this->backInk(), true, $align);
            $html .= $this->text($nx, $y + 5.5, $nw, 4, mb_strtoupper($e['first_name']), 8, $this->backInk(), false, $align);
            $style = $this->types[$e['type']] ?? ['label' => strtoupper($e['type'])];
            $sub = $e['type'] === 'athlete' ? ($e['category'] ?: $style['label']) : $style['label'];
            $html .= $this->text($nx, $y + 9.5, $nw, 3.5, mb_strtoupper($sub), 7, $this->isEuroCup() ? $this->bannerColor() : self::CATEGORY_COLOR, true, $align);
        }
        if ($withQr) {
            $y += 16 * $this->fs / $this->sy + 3;
        } elseif ($this->fields['back_name']) {
            $y += 15;
        }

        $parts = [];
        if ($this->fields['back_areas'] && $this->areas) {
            $lines = [];
            foreach ($this->areas as $a) {
                $lines[] = '<b style="color:' . ($this->isEuroCup() ? $this->bannerColor() : self::AREA_ON) . '">' . $a['no'] . '</b>&nbsp;' . htmlspecialchars($a['name'], ENT_QUOTES, 'UTF-8');
            }
            $parts[] = '<div style="font-weight:bold; color:' . ($this->isEuroCup() ? $this->bannerColor() : self::AREA_ON) . ';">AREAS</div>' . implode('<br />', $lines);
        }
        $text = trim((string) $this->template->back_text);
        if ($text !== '') {
            $parts[] = nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
        }
        $bottom = $artwork ? 83 : 77;
        if ($parts && $bottom - $y > 4) {
            $html .= $this->paragraph(3, $y, 48, $bottom - $y, implode('<div style="height:2mm"></div>', $parts), 6.5);
        }

        return $html;
    }

    // ------------------------------------------------------------- elements

    private function qr(array $e, float $x, float $y, float $size): string
    {
        if (!is_callable($e['qr'])) {
            return '';
        }
        $file = $this->tmp . '/qr_' . $e['key'] . '.svg';
        if (!is_file($file)) {
            file_put_contents($file, QrCode::format('svg')->margin(0)->errorCorrection('M')->generate(($e['qr'])()));
        }
        $s = $size * $this->fs;
        if ($this->guide) {
            return $this->placeholder($x * $this->sx, $y * $this->sy, $s, $s, 'QR');
        }
        return $this->abs($x * $this->sx, $y * $this->sy, $s, $s, '<img src="' . $file . '" style="width:' . $s . 'mm;height:' . $s . 'mm" />');
    }

    /**
     * Accessible areas: every area number of the event, the ones this badge
     * type may enter in dark blue, the rest faded (ECCC 2025 style).
     */
    private function areaRow(array $e, float $x, float $y, float $w): string
    {
        if (!$this->fields['areas'] || !$this->areas) {
            return '';
        }
        $allowed = array_flip($this->types[$e['type']]['areas'] ?? []);
        $nums = [];
        foreach ($this->areas as $a) {
            $color = isset($allowed[$a['no']]) ? self::AREA_ON : self::AREA_OFF;
            $nums[] = '<span style="color:' . $color . ';">' . $a['no'] . '</span>';
        }
        $rw = $w * $this->sx;
        $labelH = 2.6 * $this->sy;
        $numH = 5.4 * $this->sy;
        return $this->abs($x * $this->sx, $y * $this->sy, $rw, $labelH,
                $this->cell('ACCESSIBLE AREAS', 4.6 * $this->fs, $labelH, self::AREA_ON, false, 'center', 0, null, $rw - 2))
            . $this->abs($x * $this->sx, $y * $this->sy + $labelH, $rw, $numH,
                $this->cell('', 11 * $this->fs, $numH, self::AREA_ON, true, 'center', 0, implode('&nbsp;', $nums), $rw - 2));
    }

    /** Gender letter for athletes, the type label (S, VIP, MEDIA...) for everyone else. */
    private function typeTag(array $e, float $x, float $y, float $h, float $pt): string
    {
        if ($e['type'] === 'athlete') {
            if (!$this->fields['gender'] || !$e['gender']) {
                return '';
            }
            return $this->tag($x, $y, $h, $e['gender'], self::GENDER_COLORS[$e['gender']] ?? '#555555', $pt);
        }
        $style = $this->types[$e['type']] ?? ['label' => strtoupper($e['type']), 'color' => '#333333'];
        return $this->tag($x, $y, $h, $style['label'], $style['color'], $pt);
    }

    /**
     * Coloured ribbon from $x to the right card edge, label centred on the
     * visible part; a plain strip continues it into the bleed.
     */
    private function tag(float $x, float $y, float $h, string $label, string $color, float $pt): string
    {
        $rx = $x * $this->sx;
        $rw = $this->w - $rx;
        $rh = $h * $this->sy;
        $html = $this->abs($rx, $y * $this->sy, $rw, $rh,
            $this->cell($label, $pt * $this->fs, $rh, '#FFFFFF', true, 'center', 1, null, $rw),
            'background-color:' . $color . ';');
        if ($this->b > 0) {
            // starts 1 mm inside the ribbon so no hairline gap shows at the trim edge
            $html .= $this->abs($this->w - 1, $y * $this->sy, $this->b + 1, $rh, '', 'background-color:' . $color . ';');
        }
        return $html;
    }

    private function footer(array $e, float $y, float $h, float $pt): string
    {
        $text = $this->fields['club'] ? ($e['club'] ?: $e['country']) : ($e['country'] ?: $e['club']);
        return $this->band($y, $h, $this->template->footer_color ?: '#F3F1DC', mb_strtoupper((string) $text), $pt, '#000000', true);
    }

    /**
     * Full-width coloured band (into the side bleed; the footer also into the
     * bottom bleed) with centred bold text. The text box is separate and stays
     * inside the trimmed card with a safe margin, so long titles shrink to fit
     * the card rather than the bleed.
     */
    private function band(float $y, float $h, string $color, string $text, float $pt, string $ink, bool $toBottom = false): string
    {
        $ry = $y * $this->sy + $this->b;
        $rh = $h * $this->sy;
        $boxH = $toBottom ? $this->h + 2 * $this->b - $ry : $rh;
        $safe = 2.0; // mm from the trim edge
        $bg = sprintf(
            '<div style="position:absolute; left:%.2fmm; top:%.2fmm; width:%.2fmm; height:%.2fmm; background-color:%s;"></div>',
            $this->ox, $ry, $this->w + 2 * $this->b, $boxH, $color
        );
        return $bg . $this->abs($safe, $y * $this->sy, $this->w - 2 * $safe, $rh,
            $this->cell($text, $pt * $this->fs, $rh, $ink, true, 'center', 0, null, $this->w - 2 * $safe));
    }

    private function fullImage(?string $name): string
    {
        if ($this->guide) {
            return '';
        }
        $path = $this->assetPath($name);
        if (!$path) {
            return '';
        }
        $pw = $this->w + 2 * $this->b;
        $ph = $this->h + 2 * $this->b;
        return '<div style="position:absolute; left:' . $this->ox . 'mm; top:0; width:' . $pw . 'mm; height:' . $ph . 'mm;">'
            . '<img src="' . $path . '" style="width:' . $pw . 'mm; height:' . $ph . 'mm;" /></div>';
    }

    private function logo(float $x, float $y, float $w, float $h): string
    {
        if ($this->guide) {
            return $this->placeholder($x * $this->sx, $y * $this->sy, $w * $this->sx, $h * $this->sy, 'LOGO');
        }
        $path = $this->assetPath($this->template->logo);
        if (!$path) {
            return '';
        }
        // Fit inside the box keeping aspect ratio, right-aligned, centred vertically.
        $bw = $w * $this->sx;
        $bh = $h * $this->sy;
        $size = @getimagesize($path);
        $ratio = $size && $size[1] > 0 ? $size[0] / $size[1] : 1;
        $lw = min($bw, $bh * $ratio);
        $lh = $lw / $ratio;
        return $this->abs($x * $this->sx + $bw - $lw, $y * $this->sy + ($bh - $lh) / 2, $lw, $lh,
            '<img src="' . $path . '" style="width:' . $lw . 'mm;height:' . $lh . 'mm" />');
    }

    private function photo(array $e, float $x, float $y, float $w, float $h): string
    {
        $rw = $w * $this->sx;
        $rh = $h * $this->sy;
        if ($this->guide) {
            return $this->placeholder($x * $this->sx, $y * $this->sy, $rw, $rh, 'PHOTO');
        }
        $file = $e['photo_path'] ? $this->cropPhoto($e['photo_path'], $e['key'], $rw / $rh) : null;
        if ($file) {
            return $this->abs($x * $this->sx, $y * $this->sy, $rw, $rh, '<img src="' . $file . '" style="width:' . $rw . 'mm;height:' . $rh . 'mm" />');
        }
        $initials = mb_strtoupper(mb_substr($e['first_name'], 0, 1) . mb_substr($e['last_name'], 0, 1));
        return $this->abs($x * $this->sx, $y * $this->sy, $rw, $rh,
            $this->cell($initials, $rw * 0.9, $rh, '#9E9E9E', true, 'center', 0), 'background-color:#E6E6E6;');
    }

    /** Centre-crop to the photo box ratio and downscale, so mPDF never stretches. */
    private function cropPhoto(string $path, string $key, float $ratio): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return $path;
        }
        $src = @imagecreatefromstring((string) @file_get_contents($path));
        if (!$src) {
            return null;
        }
        $sw = imagesx($src);
        $sh = imagesy($src);
        if ($sw / $sh > $ratio) {
            $cw = (int) round($sh * $ratio);
            $ch = $sh;
        } else {
            $cw = $sw;
            $ch = (int) round($sw / $ratio);
        }
        $cx = (int) (($sw - $cw) / 2);
        $cy = (int) (($sh - $ch) / 2);
        $tw = min(800, $cw);
        $th = (int) round($tw / $ratio);
        $dst = imagecreatetruecolor($tw, $th);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, $cx, $cy, $tw, $th, $cw, $ch);
        $out = $this->tmp . '/photo_' . $key . '.jpg';
        imagejpeg($dst, $out, 88);
        imagedestroy($src);
        imagedestroy($dst);
        return $out;
    }

    /** Single-line text in grid units, shrunk to fit when too long. */
    private function text(float $x, float $y, float $w, float $h, string $text, float $pt, string $color, bool $bold, string $align = 'left'): string
    {
        $rh = $h * $this->sy;
        return $this->abs($x * $this->sx, $y * $this->sy, $w * $this->sx, $rh,
            $this->cell($text, $pt * $this->fs, $rh, $color, $bold, $align, 0, null, $w * $this->sx));
    }

    /** Multi-line block (back side, already escaped HTML), shrunk to fit the box. */
    private function paragraph(float $x, float $y, float $w, float $h, string $t, float $pt): string
    {
        return $this->abs($x * $this->sx, $y * $this->sy, $w * $this->sx, $h * $this->sy,
            '<div style="font-size:' . ($pt * $this->fs) . 'pt; line-height:1.3; color:' . $this->backInk() . '; text-align:center;">' . $t . '</div>');
    }

    private function isEuroCup(): bool
    {
        return ($this->template->layout ?? 'classic') === 'eurocup';
    }

    /** Back-side text colour: event blue on Euro Cup badges, black on Classic. */
    private function backInk(): string
    {
        return $this->isEuroCup() ? $this->bannerColor() : '#000000';
    }

    /**
     * One-cell table: vertical centring; with the block's overflow:auto mPDF
     * scales the content down when a long name does not fit.
     */
    private function cell(string $text, float $pt, float $h, string $color, bool $bold, string $align, float $padX, ?string $rawHtml = null, ?float $availW = null): string
    {
        if ($availW !== null) {
            $pt = $this->fitPt($rawHtml !== null ? html_entity_decode(strip_tags($rawHtml)) : $text, $pt, $bold, $availW - 2 * $padX);
        }
        $t = $rawHtml ?? htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        return '<table cellpadding="0" cellspacing="0" style="width:100%; border-collapse:collapse;"><tr>'
            . '<td style="height:' . $h . 'mm; vertical-align:middle; text-align:' . $align . '; padding:0 ' . $padX . 'mm;'
            . ' font-size:' . $pt . 'pt; color:' . $color . '; font-weight:' . ($bold ? 'bold' : 'normal') . '; white-space:nowrap;">'
            . $t . '</td></tr></table>';
    }

    /**
     * Largest font size <= $pt at which $text fits $availMm, measured with the
     * real font, so nothing runs past the trim even with bleed.
     */
    private function fitPt(string $text, float $pt, bool $bold, float $availMm): float
    {
        if (!$this->mpdf || $text === '' || $availMm <= 0) {
            return $pt;
        }
        $this->mpdf->SetFont(self::FONT, $bold ? 'B' : '', $pt);
        $w = $this->mpdf->GetStringWidth($text);
        return $w > $availMm ? max(3.0, $pt * $availMm / $w * 0.98) : $pt;
    }

    /** Absolutely positioned block in real card mm (bleed added). */
    private function abs(float $x, float $y, float $w, float $h, string $inner, string $style = ''): string
    {
        return sprintf(
            '<div style="position:absolute; left:%.2fmm; top:%.2fmm; width:%.2fmm; height:%.2fmm; overflow:auto; %s">%s</div>',
            $x + $this->b + $this->ox, $y + $this->b, $w, $h, $style, $inner
        );
    }

    private function bannerText(): string
    {
        $t = trim((string) $this->template->banner_text);
        return $t !== '' ? $t : $this->eventName;
    }

    private function bannerColor(): string
    {
        return $this->template->banner_color ?: '#0072BC';
    }

    private function assetPath(?string $name): ?string
    {
        if (!$name) {
            return null;
        }
        $path = BadgeService::storageDir((int) $this->template->event_id) . '/' . basename($name);
        return is_file($path) ? $path : null;
    }
}
