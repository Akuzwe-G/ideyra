<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ideyra:brand-assets')]
#[Description('Generate browser and PWA icons from the official Ideyra PNG icon')]
class GenerateBrandAssets extends Command
{
    public function handle(): int
    {
        $directory = public_path('assets/brand');
        $sourcePath = $directory.'/ideyra-icon.png';
        if (! is_file($sourcePath) || ! extension_loaded('gd')) {
            $this->error('Official public/assets/brand/ideyra-icon.png and PHP GD are required.');

            return self::FAILURE;
        }
        $source = imagecreatefrompng($sourcePath);
        if ($source === false) {
            $this->error('The official icon must be a valid PNG.');

            return self::FAILURE;
        }
        imagepalettetotruecolor($source);
        $left = imagesx($source);
        $top = imagesy($source);
        $right = -1;
        $bottom = -1;
        for ($y = 0; $y < imagesy($source); $y++) {
            for ($x = 0; $x < imagesx($source); $x++) {
                if (((imagecolorat($source, $x, $y) >> 24) & 0x7F) < 120) {
                    $left = min($left, $x);
                    $top = min($top, $y);
                    $right = max($right, $x);
                    $bottom = max($bottom, $y);
                }
            }
        }
        if ($right < $left) {
            $this->error('The supplied icon contains no visible artwork.');

            return self::FAILURE;
        }
        $artwork = imagecrop($source, ['x' => $left, 'y' => $top, 'width' => $right - $left + 1, 'height' => $bottom - $top + 1]);
        $variants = [
            'favicon-16x16.png' => [16, false, .90],
            'favicon-32x32.png' => [32, false, .90],
            'apple-touch-icon.png' => [180, true, .80],
            'pwa-192x192.png' => [192, false, .88],
            'pwa-512x512.png' => [512, false, .88],
            'pwa-maskable-192x192.png' => [192, true, .54],
            'pwa-maskable-512x512.png' => [512, true, .54],
        ];
        foreach ($variants as $filename => [$size, $opaque, $occupancy]) {
            $canvas = imagecreatetruecolor($size, $size);
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $background = $opaque ? imagecolorallocate($canvas, 255, 255, 255) : imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefill($canvas, 0, 0, $background);
            $scale = $size * $occupancy / max(imagesx($artwork), imagesy($artwork));
            $width = max(1, (int) round(imagesx($artwork) * $scale));
            $height = max(1, (int) round(imagesy($artwork) * $scale));
            imagealphablending($canvas, $opaque);
            imagecopyresampled($canvas, $artwork, (int) (($size - $width) / 2), (int) (($size - $height) / 2), 0, 0, $width, $height, imagesx($artwork), imagesy($artwork));
            imagepng($canvas, $directory.'/'.$filename, 9);
        }
        $images = [16 => file_get_contents($directory.'/favicon-16x16.png'), 32 => file_get_contents($directory.'/favicon-32x32.png')];
        $ico = pack('vvv', 0, 1, count($images));
        $offset = 6 + count($images) * 16;
        foreach ($images as $size => $png) {
            $ico .= pack('CCCCvvVV', $size, $size, 0, 0, 1, 32, strlen($png), $offset);
            $offset += strlen($png);
        }
        $ico .= implode('', $images);
        file_put_contents($directory.'/favicon.ico', $ico);
        file_put_contents(public_path('favicon.ico'), $ico);
        $this->info('Official icon derivatives generated. Source logos were not modified.');

        return self::SUCCESS;
    }
}
