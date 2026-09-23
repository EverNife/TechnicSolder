<?php

namespace App\Libraries;

use App\Models\Mod;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

class ModArchiveStore
{
    public const MAX_KILOBYTES = 102400;

    /**
     * Write the archive a launcher downloads for this mod version, wrapping a bare jar
     * into the mods/ layout, and return the checksum of what landed on disk.
     *
     * @return array{md5: string, filesize: int}
     *
     * @throws InvalidArgumentException when the names or the upload cannot become an archive
     * @throws ArchiveExistsException when the target exists and $replace is false
     * @throws RuntimeException when the repository cannot hold a file
     */
    public function store(Mod $mod, string $version, UploadedFile $file, bool $replace): array
    {
        $repo = config('solder.repo_location');

        if (filter_var($repo, FILTER_VALIDATE_URL)) {
            throw new RuntimeException("Uploads need SOLDER_REPO_LOCATION to be a local directory; it is set to a URL ({$repo}). Point it at the directory nginx serves as the mirror root.");
        }

        $this->assertPathSafe('mod name', $mod->name);
        $this->assertPathSafe('version', $version);

        $isJar = $this->assertArchive($file);

        $dir = $repo.'mods/'.$mod->name;
        $target = $dir.'/'.$mod->name.'-'.$version.'.zip';

        if (! $replace && file_exists($target)) {
            throw new ArchiveExistsException($target);
        }

        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create {$dir}. Check that the mods directory is writable by the web server user.");
        }

        $temp = $target.'.uploading-'.Str::random(8);

        try {
            if ($isJar) {
                $this->wrapJar($file, $temp);
            } else {
                $file->move(dirname($temp), basename($temp));
            }

            if (! rename($temp, $target)) {
                throw new RuntimeException("Could not write {$target}. Check that the mods directory is writable by the web server user.");
            }
        } finally {
            if (file_exists($temp)) {
                unlink($temp);
            }
        }

        clearstatcache(true, $target);

        return ['md5' => md5_file($target), 'filesize' => filesize($target)];
    }

    private function assertPathSafe(string $label, string $value): void
    {
        if ($value === '' || str_starts_with($value, '.') || strpbrk($value, "/\\\0") !== false || str_contains($value, '..')) {
            throw new InvalidArgumentException("The {$label} \"{$value}\" cannot be used in a file path: it must not be empty, start with a dot, or contain '/', '\\', '..' or NUL. Rename it before uploading.");
        }
    }

    /**
     * @return bool whether the upload is a jar that still needs wrapping
     */
    private function assertArchive(UploadedFile $file): bool
    {
        $extension = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));

        if (! in_array($extension, ['zip', 'jar'], true)) {
            throw new InvalidArgumentException('The file must be a .zip or a .jar; got "'.$file->getClientOriginalName().'".');
        }

        $zip = new ZipArchive;
        if ($zip->open($file->getRealPath(), ZipArchive::RDONLY) !== true) {
            throw new InvalidArgumentException('The file "'.$file->getClientOriginalName().'" is not a readable zip archive. Check that the upload is complete and not corrupted.');
        }
        $zip->close();

        return $extension === 'jar';
    }

    private function wrapJar(UploadedFile $file, string $temp): void
    {
        $jarName = preg_replace('/[^A-Za-z0-9._+-]/', '_', basename($file->getClientOriginalName()));

        $zip = new ZipArchive;
        if ($zip->open($temp, ZipArchive::CREATE | ZipArchive::EXCL) !== true
            || ! $zip->addFile($file->getRealPath(), 'mods/'.$jarName)
            || ! $zip->close()) {
            throw new RuntimeException("Could not build {$temp}. Check that the mods directory is writable by the web server user.");
        }
    }
}
