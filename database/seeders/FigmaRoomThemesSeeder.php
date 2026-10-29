<?php

namespace Database\Seeders;

use App\Domain\Media\ImageUploadService;
use App\Models\RoomTheme;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;

/**
 * The six backgrounds the app's Create Room screen shows under Room Background
 * (Figma 397:2853, file ODEXeeT7yuiT9r7AG4Q3Gh), as free room themes.
 *
 * The pictures sit next to this file (assets/room-themes, resized from the Figma
 * export) and go through the same ImageUploadService the admin's theme upload uses,
 * so they land on whichever uploads disk is configured. Re-running it is harmless:
 * a theme that already exists by name keeps its pictures.
 *
 *   php artisan db:seed --class=FigmaRoomThemesSeeder
 */
class FigmaRoomThemesSeeder extends Seeder
{
    private const THEMES = [
        'royal-stairway' => 'Royal Stairway',
        'crimson-gate'   => 'Crimson Gate',
        'violet-court'   => 'Violet Court',
        'golden-hour'    => 'Golden Hour',
        'golden-portal'  => 'Golden Portal',
        'emerald-hall'   => 'Emerald Hall',
    ];

    public function run(ImageUploadService $uploads): void
    {
        $dir = __DIR__.'/assets/room-themes';

        foreach (self::THEMES as $file => $name) {
            if (RoomTheme::where('name', $name)->exists()) {
                continue;
            }

            $background = $uploads->store($this->file("$dir/$file.jpg"), 'room-theme-backgrounds');
            $preview = $uploads->store($this->file("$dir/$file-preview.jpg"), 'room-theme-previews');

            RoomTheme::create([
                'name'           => $name,
                'background_url' => $background['url'],
                'preview_url'    => $preview['url'],
                'is_premium'     => false,
                'coin_price'     => 0,
                'is_active'      => true,
            ]);
        }
    }

    private function file(string $path): UploadedFile
    {
        // `test: true` — the file was not posted, so skip is_uploaded_file().
        return new UploadedFile($path, basename($path), 'image/jpeg', null, true);
    }
}
