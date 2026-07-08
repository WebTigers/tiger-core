<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Media_Image — GD-backed image variant generation (thumbnails / sized copies).
 *
 * OPTIONAL: everything degrades when GD is absent (hasGd()/supports() return false) — the
 * upload pipeline then falls back to a browser-generated thumbnail. When GD IS present it
 * produces the configured presets server-side.
 *
 * Rules (per the product spec): **contain, never crop** (scale to fit the longest edge),
 * **never upscale** (a preset larger than the source is skipped — the original serves that
 * size), preserve transparency (PNG/WebP), auto-rotate from EXIF (JPEG), and favor
 * **quality over compression** for the lossy formats.
 *
 * @api
 */
class Tiger_Media_Image
{
    /** Is GD available at all? */
    public static function hasGd()
    {
        return extension_loaded('gd') && function_exists('imagecreatetruecolor');
    }

    /** Can we process this MIME with the GD build present? */
    public static function supports($mime)
    {
        if (!self::hasGd()) {
            return false;
        }
        $type = self::_type($mime);
        if ($type === null) {
            return false;
        }
        $gd   = gd_info();
        $flag = ['jpeg' => 'JPEG Support', 'png' => 'PNG Support', 'gif' => 'GIF Read Support', 'webp' => 'WebP Support'];
        return !empty($gd[$flag[$type]]);
    }

    /**
     * Generate downscaled variants to temp files.
     *
     * @param  string $sourcePath the original on disk
     * @param  string $mime
     * @param  array  $presets    ['thumbnail'=>200, 'small'=>640, …] (longest-edge px)
     * @param  int    $quality    JPEG/WebP quality (0-100; high = better)
     * @return array  ['thumbnail'=>['path'=>tmp,'width'=>w,'height'=>h,'mime'=>mime], …]
     *                — only presets SMALLER than the source (no upscaling); [] if unsupported.
     */
    public static function variants($sourcePath, $mime, array $presets, $quality = 90)
    {
        if (!self::supports($mime)) {
            return [];
        }
        $src = self::_load($sourcePath, $mime);
        if (!$src) {
            return [];
        }
        self::_orient($src, $sourcePath, $mime);

        $sw = imagesx($src);
        $sh = imagesy($src);
        $long = max($sw, $sh);

        $out = [];
        foreach ($presets as $name => $edge) {
            $edge = (int) $edge;
            if ($edge <= 0 || $edge >= $long) {
                continue;   // never upscale — the original covers this size
            }
            $scale = $edge / $long;
            $nw = max(1, (int) round($sw * $scale));
            $nh = max(1, (int) round($sh * $scale));

            $dst = self::_canvas($nw, $nh, $mime);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $sw, $sh);

            $tmp = tempnam(sys_get_temp_dir(), 'tmedia');
            if ($tmp !== false && self::_save($dst, $mime, $tmp, $quality)) {
                $out[$name] = ['path' => $tmp, 'width' => $nw, 'height' => $nh, 'mime' => (string) $mime];
            }
            imagedestroy($dst);
        }
        imagedestroy($src);
        return $out;
    }

    /** A destination canvas, transparent for formats that carry alpha. */
    protected static function _canvas($w, $h, $mime)
    {
        $dst = imagecreatetruecolor($w, $h);
        $type = self::_type($mime);
        if ($type === 'png' || $type === 'webp' || $type === 'gif') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $w, $h, $transparent);
        }
        return $dst;
    }

    protected static function _load($path, $mime)
    {
        switch (self::_type($mime)) {
            case 'jpeg': return @imagecreatefromjpeg($path);
            case 'png':  return @imagecreatefrompng($path);
            case 'gif':  return @imagecreatefromgif($path);
            case 'webp': return @imagecreatefromwebp($path);
        }
        return false;
    }

    protected static function _save($img, $mime, $path, $quality)
    {
        $q = max(1, min(100, (int) $quality));
        switch (self::_type($mime)) {
            case 'jpeg': return imagejpeg($img, $path, $q);        // lossy → high quality
            case 'webp': return imagewebp($img, $path, $q);        // lossy → high quality
            case 'png':  return imagepng($img, $path, 6);          // lossless; 6 = balanced (quality unaffected)
            case 'gif':  return imagegif($img, $path);
        }
        return false;
    }

    /** Auto-rotate a JPEG from its EXIF Orientation so thumbnails aren't sideways. */
    protected static function _orient(&$img, $path, $mime)
    {
        if (self::_type($mime) !== 'jpeg' || !function_exists('exif_read_data')) {
            return;
        }
        $exif = @exif_read_data($path);
        $o = isset($exif['Orientation']) ? (int) $exif['Orientation'] : 0;
        if ($o === 3) {
            $img = imagerotate($img, 180, 0);
        } elseif ($o === 6) {
            $img = imagerotate($img, -90, 0);
        } elseif ($o === 8) {
            $img = imagerotate($img, 90, 0);
        }
    }

    /** MIME → GD type token, or null if unsupported. */
    protected static function _type($mime)
    {
        $map = [
            'image/jpeg' => 'jpeg', 'image/pjpeg' => 'jpeg', 'image/jpg' => 'jpeg',
            'image/png'  => 'png',  'image/gif' => 'gif', 'image/webp' => 'webp',
        ];
        return $map[strtolower((string) $mime)] ?? null;
    }
}
