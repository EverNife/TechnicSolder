<?php

namespace App\Http\Controllers\Api;

use App\Http\ApiAuthContext;
use App\Http\Controllers\Controller;
use App\Libraries\ArchiveExistsException;
use App\Libraries\ModArchiveStore;
use App\Models\Build;
use App\Models\Mod;
use App\Models\Modversion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use RuntimeException;

class ModversionController extends Controller
{
    /** Upper bound on slices per archive: at 100 MB each, archives up to about 2 GB. */
    public const MAX_PARTS = 20;

    public function show(string $slug, string $version): JsonResponse
    {
        $auth = ApiAuthContext::fromRequest();

        $mod = Cache::remember('mod:'.$slug, now()->addMinutes(5), function () use ($slug) {
            return Mod::with('versions')->where('name', $slug)->first();
        });

        if (! $mod) {
            return response()->json(['error' => 'Mod does not exist'], 404);
        }

        $modVersion = $mod->versions()
            ->with('builds.modpack')
            ->where('version', $version)
            ->first();

        if (! $modVersion instanceof Modversion) {
            return response()->json(['error' => 'Mod version does not exist'], 404);
        }

        $response = $modVersion->only([
            'id',
            'md5',
            'filesize',
            'url',
        ]);

        $response['builds'] = $modVersion->builds
            ->filter(fn (Build $build) => $build->isAccessibleBy($auth))
            ->sortBy('id')
            ->values()
            ->map(fn (Build $build) => [
                'id' => $build->id,
                'version' => $build->version,
                'modpack' => [
                    'id' => $build->modpack->id,
                    'name' => $build->modpack->slug,
                    'display_name' => $build->modpack->name,
                ],
            ])
            ->all();

        $perm = $auth->user?->permission;
        if ($perm?->solder_full || $perm?->mods_manage) {
            $response['notes'] = $modVersion->notes;
        }

        return response()->json($response);
    }

    public function store(Request $request, string $slug): JsonResponse
    {
        $this->authorize('create', Modversion::class);

        $mod = Mod::where('name', $slug)->first();

        if (! $mod) {
            return response()->json(['error' => 'Mod not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'version' => 'required',
            'md5' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        if ($mod->versions()->where('version', $request->input('version'))->exists()) {
            return response()->json(['error' => 'Version already exists for this mod.'], 422);
        }

        $modversion = $mod->versions()->create($request->only(['version', 'md5', 'filesize', 'notes']));

        Cache::forget('mod:'.$slug);
        Cache::forget('mods');

        return response()->json($modversion, 201);
    }

    public function update(Request $request, string $slug, string $version): JsonResponse
    {
        $this->authorize('update', Modversion::class);

        $mod = Mod::where('name', $slug)->first();

        if (! $mod) {
            return response()->json(['error' => 'Mod not found.'], 404);
        }

        $modversion = $mod->versions()->where('version', $version)->first();

        if (! $modversion) {
            return response()->json(['error' => 'Mod version not found.'], 404);
        }

        $modversion->update($request->only(['md5', 'filesize', 'notes']));

        Cache::forget('mod:'.$slug);

        return response()->json($modversion);
    }

    /**
     * Store the archive for a mod version, creating the version when it does not exist yet.
     */
    public function upload(Request $request, ModArchiveStore $store, string $slug, string $version): JsonResponse
    {
        $mod = Mod::where('name', $slug)->first();

        if (! $mod) {
            return response()->json(['error' => 'Mod not found. Create it first with POST /api/mod.'], 404);
        }

        /** @var Modversion|null $modversion */
        $modversion = $mod->versions()->where('version', $version)->first();

        $this->authorize($modversion ? 'update' : 'create', Modversion::class);

        $validator = Validator::make($request->all(), [
            'file' => 'required|file|max:'.ModArchiveStore::MAX_KILOBYTES,
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        return $this->storeArchive($request, $store, $mod, $modversion, $version, $request->file('file'));
    }

    /**
     * Receive one slice of an archive too big for a single request (a proxy in front of Solder,
     * such as Cloudflare, caps request bodies at 100 MB). Slices wait in the temp directory until all
     * `parts` have arrived, in any order; the joined file then goes through the same store as
     * {@see upload()}.
     */
    public function uploadPart(Request $request, ModArchiveStore $store, string $slug, string $version): JsonResponse
    {
        $mod = Mod::where('name', $slug)->first();

        if (! $mod) {
            return response()->json(['error' => 'Mod not found. Create it first with POST /api/mod.'], 404);
        }

        /** @var Modversion|null $modversion */
        $modversion = $mod->versions()->where('version', $version)->first();

        $this->authorize($modversion ? 'update' : 'create', Modversion::class);

        $validator = Validator::make($request->all(), [
            'file' => 'required|file|max:'.ModArchiveStore::MAX_KILOBYTES,
            'filename' => 'required|string',
            'parts' => 'required|integer|min:2|max:'.self::MAX_PARTS,
            'part' => 'required|integer|min:0|lt:parts',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $parts = $request->integer('parts');
        $filename = $request->string('filename')->toString();
        // One directory per mod version and file, so a resent slice overwrites its own copy.
        $dir = sys_get_temp_dir().'/solder-upload-parts-'.md5(implode("\0", [$mod->id, $version, $filename, $parts]));
        File::ensureDirectoryExists($dir);
        $request->file('file')->move($dir, (string) $request->integer('part'));

        $received = count(array_filter(range(0, $parts - 1), fn (int $part) => file_exists("{$dir}/{$part}")));

        if ($received < $parts) {
            return response()->json(['received' => $received, 'parts' => $parts], 202);
        }

        try {
            $joined = "{$dir}/joined";
            $out = fopen($joined, 'wb');
            for ($part = 0; $part < $parts; $part++) {
                $in = fopen("{$dir}/{$part}", 'rb');
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
            fclose($out);

            // test: true because the joined file never was a PHP upload, and move() would refuse it.
            $file = new UploadedFile($joined, $filename, null, null, true);

            return $this->storeArchive($request, $store, $mod, $modversion, $version, $file);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    private function storeArchive(Request $request, ModArchiveStore $store, Mod $mod, ?Modversion $modversion, string $version, UploadedFile $file): JsonResponse
    {
        $slug = $mod->name;

        try {
            $archive = $store->store($mod, $version, $file, $request->boolean('replace'));
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (ArchiveExistsException) {
            return response()->json(['error' => "An archive already exists for {$slug} {$version}. Resend with replace=true to overwrite it."], 409);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        if ($modversion) {
            $modversion->update($archive);
        } else {
            $modversion = $mod->versions()->create($archive + [
                'version' => $version,
                'notes' => $request->input('notes'),
            ]);
        }

        Cache::forget('mod:'.$slug);
        Cache::forget('mods');

        return response()->json(
            $modversion->only(['id', 'version', 'md5', 'filesize', 'url']),
            $modversion->wasRecentlyCreated ? 201 : 200
        );
    }

    public function destroy(string $slug, string $version): JsonResponse
    {
        $this->authorize('delete', Modversion::class);

        $mod = Mod::where('name', $slug)->first();

        if (! $mod) {
            return response()->json(['error' => 'Mod not found.'], 404);
        }

        /** @var Modversion|null $modversion */
        $modversion = $mod->versions()->where('version', $version)->first();

        if (! $modversion) {
            return response()->json(['error' => 'Mod version not found.'], 404);
        }

        if ($modversion->builds()->exists()) {
            return response()->json([
                'error' => 'Mod version is in use by '.$modversion->builds()->count().' build(s) and cannot be deleted.',
            ], 409);
        }

        $modversion->delete();

        Cache::forget('mod:'.$slug);
        Cache::forget('mods');

        return response()->json(['success' => 'Mod version deleted.']);
    }
}
