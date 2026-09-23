<?php

namespace Tests\Feature;

use App\Libraries\ModArchiveStore;
use App\Models\Mod;
use App\Models\Modversion;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use ZipArchive;

final class ModUploadTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private string $base;

    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        $this->token = User::find(1)->createToken('test')->plainTextToken;

        $this->base = sys_get_temp_dir().'/solder-upload-'.uniqid();
        $this->repo = $this->base.'/repo/';
        mkdir($this->repo, 0755, true);
        config(['solder.repo_location' => $this->repo, 'solder.mirror_url' => 'https://mirror.test/']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);

        parent::tearDown();
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function zipContent(array $entries = ['mods/example.jar' => 'jar-bytes']): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        $content = file_get_contents($path);
        unlink($path);

        return $content;
    }

    private function upload(string $slug, string $version, array $data, ?string $token = null): TestResponse
    {
        return $this->postJson("api/mod/{$slug}/{$version}/file", $data, [
            'Authorization' => 'Bearer '.($token ?? $this->token),
        ]);
    }

    private function archivePath(string $mod, string $version): string
    {
        return $this->repo."mods/{$mod}/{$mod}-{$version}.zip";
    }

    /**
     * @return list<string>
     */
    private function filesUnderBase(): array
    {
        return array_map(fn ($file) => $file->getPathname(), File::allFiles($this->base, true));
    }

    private function userWithPermissions(array $perms): User
    {
        $unique = uniqid();
        $user = new User;
        $user->username = 'upload-'.$unique;
        $user->email = 'upload-'.$unique.'@example.com';
        $user->password = 'password';
        $user->created_ip = '127.0.0.1';
        $user->created_by_user_id = 1;
        $user->updated_by_user_id = 1;
        $user->updated_by_ip = '127.0.0.1';
        $user->save();

        $permission = new UserPermission;
        $permission->user_id = $user->id;
        foreach ($perms as $key => $value) {
            $permission->{$key} = $value;
        }
        $permission->save();

        return $user;
    }

    public function test_api_zip_creates_version_and_writes_archive(): void
    {
        $content = $this->zipContent();

        $response = $this->upload('testmod', '2.0', [
            'file' => UploadedFile::fake()->createWithContent('testmod.zip', $content),
            'notes' => 'uploaded',
        ]);

        $path = $this->archivePath('testmod', '2.0');
        $response->assertStatus(201)->assertJson([
            'version' => '2.0',
            'md5' => md5($content),
            'filesize' => strlen($content),
            'url' => 'https://mirror.test/mods/testmod/testmod-2.0.zip',
        ]);
        $this->assertFileExists($path);
        $this->assertSame(md5($content), md5_file($path));
        $this->assertDatabaseHas('modversions', [
            'version' => '2.0',
            'md5' => md5($content),
            'filesize' => strlen($content),
            'notes' => 'uploaded',
        ]);
    }

    public function test_api_jar_is_wrapped_into_mods_directory(): void
    {
        $response = $this->upload('testmod', '2.0', [
            'file' => UploadedFile::fake()->createWithContent('TestMod 1.2.JAR', $this->zipContent(['META-INF/MANIFEST.MF' => 'x'])),
        ]);

        $response->assertStatus(201);
        $path = $this->archivePath('testmod', '2.0');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $this->assertSame(1, $zip->numFiles);
        $this->assertSame('mods/TestMod_1.2.JAR', $zip->getNameIndex(0));
        $zip->close();
        $response->assertJson(['md5' => md5_file($path), 'filesize' => filesize($path)]);
    }

    public function test_api_existing_version_without_archive_is_filled(): void
    {
        $content = $this->zipContent();

        $response = $this->upload('testmod', '1.0', [
            'file' => UploadedFile::fake()->createWithContent('testmod.zip', $content),
        ]);

        $response->assertStatus(200)->assertJson(['version' => '1.0', 'md5' => md5($content)]);
        $this->assertSame(1, Modversion::where('version', '1.0')->whereHas('mod', fn ($q) => $q->where('name', 'testmod'))->count());
        $this->assertDatabaseHas('modversions', ['version' => '1.0', 'md5' => md5($content), 'filesize' => strlen($content)]);
    }

    public function test_api_existing_archive_needs_replace(): void
    {
        $first = $this->zipContent(['a.txt' => 'first']);
        $second = $this->zipContent(['a.txt' => 'second']);
        $this->upload('testmod', '2.0', ['file' => UploadedFile::fake()->createWithContent('a.zip', $first)])->assertStatus(201);

        $this->upload('testmod', '2.0', ['file' => UploadedFile::fake()->createWithContent('a.zip', $second)])
            ->assertStatus(409)
            ->assertJson(['error' => 'An archive already exists for testmod 2.0. Resend with replace=true to overwrite it.']);
        $this->assertSame(md5($first), md5_file($this->archivePath('testmod', '2.0')));

        $this->upload('testmod', '2.0', ['file' => UploadedFile::fake()->createWithContent('a.zip', $second), 'replace' => true])
            ->assertStatus(200)
            ->assertJson(['md5' => md5($second)]);
        $this->assertSame(md5($second), md5_file($this->archivePath('testmod', '2.0')));
        $this->assertDatabaseHas('modversions', ['version' => '2.0', 'md5' => md5($second)]);
    }

    public function test_api_unknown_mod_returns_404(): void
    {
        $this->upload('nope', '1.0', ['file' => UploadedFile::fake()->createWithContent('a.zip', $this->zipContent())])
            ->assertStatus(404)
            ->assertJson(['error' => 'Mod not found. Create it first with POST /api/mod.']);
    }

    public function test_api_without_token_returns_401(): void
    {
        $this->postJson('api/mod/testmod/2.0/file', ['file' => UploadedFile::fake()->createWithContent('a.zip', $this->zipContent())])
            ->assertStatus(401);
        $this->assertSame([], $this->filesUnderBase());
    }

    public function test_api_without_mods_manage_returns_403(): void
    {
        $token = $this->userWithPermissions(['mods_create' => true])->createToken('test')->plainTextToken;

        $this->upload('testmod', '2.0', ['file' => UploadedFile::fake()->createWithContent('a.zip', $this->zipContent())], $token)
            ->assertStatus(403);
        $this->upload('testmod', '1.0', ['file' => UploadedFile::fake()->createWithContent('a.zip', $this->zipContent())], $token)
            ->assertStatus(403);
        $this->assertSame([], $this->filesUnderBase());
    }

    public function test_api_rejects_invalid_uploads_with_422(): void
    {
        $cases = [
            'wrong extension' => ['file' => UploadedFile::fake()->createWithContent('mod.rar', $this->zipContent())],
            'corrupt zip' => ['file' => UploadedFile::fake()->createWithContent('mod.zip', 'not a zip')],
            'missing file' => [],
            'over the limit' => ['file' => UploadedFile::fake()->create('mod.zip', ModArchiveStore::MAX_KILOBYTES + 1)],
        ];

        foreach ($cases as $label => $data) {
            $this->upload('testmod', '2.0', $data)->assertStatus(422)->assertJsonStructure(['error']);
            $this->assertSame([], $this->filesUnderBase(), $label);
        }

        $this->assertDatabaseMissing('modversions', ['version' => '2.0']);
    }

    public function test_api_rejects_unsafe_version_and_writes_nothing(): void
    {
        foreach (['..%5C..%5Cescape', '.hidden', '1.0..2'] as $version) {
            $this->upload('testmod', $version, ['file' => UploadedFile::fake()->createWithContent('a.zip', $this->zipContent())])
                ->assertStatus(422)
                ->assertJsonStructure(['error']);
        }

        $this->assertSame([], $this->filesUnderBase());
        $this->assertSame([$this->base.DIRECTORY_SEPARATOR.'repo'], File::directories($this->base));
    }

    public function test_api_rejects_unsafe_mod_name(): void
    {
        Mod::create(['name' => '.dotted', 'pretty_name' => 'Dotted']);

        $this->upload('.dotted', '1.0', ['file' => UploadedFile::fake()->createWithContent('a.zip', $this->zipContent())])
            ->assertStatus(422)
            ->assertJsonPath('error', fn (string $error) => str_contains($error, 'mod name'));
    }

    public function test_api_remote_repository_returns_409_with_guidance(): void
    {
        config(['solder.repo_location' => 'https://mirror.test/']);

        $this->upload('testmod', '2.0', ['file' => UploadedFile::fake()->createWithContent('a.zip', $this->zipContent())])
            ->assertStatus(409)
            ->assertJsonPath('error', 'Uploads need SOLDER_REPO_LOCATION to be a local directory; it is set to a URL (https://mirror.test/). Point it at the directory nginx serves as the mirror root.');
    }

    public function test_no_temporary_file_is_left_behind(): void
    {
        $this->upload('testmod', '2.0', ['file' => UploadedFile::fake()->createWithContent('a.jar', $this->zipContent())])->assertStatus(201);
        $this->upload('testmod', '2.0', ['file' => UploadedFile::fake()->createWithContent('a.zip', $this->zipContent()), 'replace' => true])->assertStatus(200);
        $this->upload('testmod', '2.0', ['file' => UploadedFile::fake()->createWithContent('a.zip', $this->zipContent())])->assertStatus(409);

        $this->assertSame([$this->archivePath('testmod', '2.0')], array_map(
            fn ($path) => str_replace('\\', '/', $path),
            $this->filesUnderBase()
        ));
    }

    public function test_web_upload_creates_version(): void
    {
        $content = $this->zipContent();
        $mod = Mod::where('name', 'testmod')->first();

        $this->actingAs(User::find(1))
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->post('/mod/upload-version', [
                'mod-id' => $mod->id,
                'version' => '3.0',
                'file' => UploadedFile::fake()->createWithContent('a.zip', $content),
            ])
            ->assertOk()
            ->assertJson(['status' => 'success', 'version' => '3.0', 'md5' => md5($content)]);

        $this->assertSame(md5($content), md5_file($this->archivePath('testmod', '3.0')));
        $this->assertDatabaseHas('modversions', ['mod_id' => $mod->id, 'version' => '3.0', 'md5' => md5($content)]);
    }

    public function test_web_upload_existing_archive_returns_409_until_replace(): void
    {
        $mod = Mod::where('name', 'testmod')->first();
        $post = fn (array $extra = []) => $this->actingAs(User::find(1))
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->post('/mod/upload-version', $extra + [
                'mod-id' => $mod->id,
                'version' => '1.0',
                'file' => UploadedFile::fake()->createWithContent('a.zip', $this->zipContent()),
            ]);

        $post()->assertOk()->assertJson(['status' => 'success']);
        $post()->assertStatus(409)->assertJson(['status' => 'error']);
        $post(['replace' => '1'])->assertOk()->assertJson(['status' => 'success']);
    }

    public function test_web_upload_rejects_path_traversal(): void
    {
        $mod = Mod::where('name', 'testmod')->first();

        $this->actingAs(User::find(1))
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->post('/mod/upload-version', [
                'mod-id' => $mod->id,
                'version' => '../../escape',
                'file' => UploadedFile::fake()->createWithContent('a.zip', $this->zipContent()),
            ])
            ->assertOk()
            ->assertJson(['status' => 'error']);

        $this->assertSame([], $this->filesUnderBase());
    }

    public function test_web_upload_requires_ajax(): void
    {
        $this->actingAs(User::find(1))
            ->post('/mod/upload-version', ['mod-id' => 1, 'version' => '1.0'])
            ->assertNotFound();
    }

    public function test_web_upload_denied_without_mods_manage(): void
    {
        $mod = Mod::where('name', 'testmod')->first();

        $this->actingAs($this->userWithPermissions(['mods_create' => true]))
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->post('/mod/upload-version', [
                'mod-id' => $mod->id,
                'version' => '3.0',
                'file' => UploadedFile::fake()->createWithContent('a.zip', $this->zipContent()),
            ])
            ->assertRedirect('/dashboard');

        $this->assertSame([], $this->filesUnderBase());
    }
}
