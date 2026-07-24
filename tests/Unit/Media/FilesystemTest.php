<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Media;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Media_Storage_Filesystem;

/**
 * Tiger_Media_Storage_Filesystem — the local-disk media adapter, and the P1 security target.
 *
 * The load-bearing behaviors under test, all against a throwaway sys_get_temp_dir() sandbox:
 *   - the **path-traversal guard**: a key holding `..`, a leading `/`, or backslashes can NEVER
 *     resolve to a path outside the configured root — the escape either throws or is neutralized,
 *     and we prove no byte ever lands outside the sandbox;
 *   - the **public/private root split**: public files map under the docroot with a real url();
 *     private files live outside it and url() is '' (served only via the ACL streamer route);
 *   - a full **put/write/get/exists/size/delete roundtrip** for both visibilities.
 *
 * We drive absolute roots so Tiger_Media_Storage_Filesystem::_absolute() uses them verbatim
 * (a leading '/' path is taken as-is; a relative one would hang off APPLICATION_ROOT).
 */
#[CoversClass(Tiger_Media_Storage_Filesystem::class)]
final class FilesystemTest extends UnitTestCase
{
    private const PUBLIC_URL = '/_media';

    /** @var string the sandbox root that both roots + the "outside" escape target live under */
    private string $sandbox;
    private string $publicRoot;
    private string $privateRoot;
    private string $outside;   // a sibling dir a traversal would try to reach

    protected function setUp(): void
    {
        parent::setUp();
        // A unique sandbox per test so nothing leaks between cases.
        $this->sandbox     = sys_get_temp_dir() . '/tiger-fs-' . bin2hex(random_bytes(6));
        $this->publicRoot  = $this->sandbox . '/public/_media';
        $this->privateRoot = $this->sandbox . '/storage/media';
        $this->outside     = $this->sandbox . '/OUTSIDE';   // sibling of both roots
        mkdir($this->publicRoot, 0777, true);
        mkdir($this->privateRoot, 0777, true);
        mkdir($this->outside, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->sandbox);
        parent::tearDown();
    }

    /** Build an adapter pointed at the sandbox roots (absolute → used verbatim). */
    private function adapter(): Tiger_Media_Storage_Filesystem
    {
        return new Tiger_Media_Storage_Filesystem([
            'public_root'  => $this->publicRoot,
            'private_root' => $this->privateRoot,
            'public_url'   => self::PUBLIC_URL,
        ]);
    }

    /** Write a scratch source file (the `put()` copy-from) and return its path. */
    private function sourceFile(string $bytes): string
    {
        $p = $this->sandbox . '/src-' . bin2hex(random_bytes(4)) . '.bin';
        file_put_contents($p, $bytes);
        return $p;
    }

    /** Recursive delete for the sandbox teardown. */
    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            @unlink($dir);
            return;
        }
        foreach (scandir($dir) as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $path = $dir . '/' . $e;
            is_dir($path) && !is_link($path) ? $this->rmrf($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    // ---- Roundtrip -----------------------------------------------------------------------

    #[Test]
    public function put_get_exists_size_delete_round_trips_for_public(): void
    {
        $fs   = $this->adapter();
        $key  = 'org-1/images/photo.jpg';
        $body = 'the public bytes';
        $src  = $this->sourceFile($body);

        $this->assertFalse($fs->exists($key, 'public'), 'nothing there yet');
        $this->assertSame(0, $fs->size($key, 'public'), 'missing size is 0, not an error');

        $fs->put($key, $src, 'public', 'image/jpeg');

        $this->assertTrue($fs->exists($key, 'public'));
        $this->assertSame($body, $fs->get($key, 'public'));
        $this->assertSame(strlen($body), $fs->size($key, 'public'));
        // The bytes landed UNDER the public root, nowhere else.
        $this->assertFileExists($this->publicRoot . '/' . $key);

        $fs->delete($key, 'public');
        $this->assertFalse($fs->exists($key, 'public'));
    }

    #[Test]
    public function write_get_delete_round_trips_for_private(): void
    {
        $fs   = $this->adapter();
        $key  = 'org-2/files/book.epub';
        $body = 'the private bytes';

        $fs->write($key, $body, 'private', 'application/epub+zip');

        $this->assertTrue($fs->exists($key, 'private'));
        $this->assertSame($body, $fs->get($key, 'private'));
        $this->assertSame(strlen($body), $fs->size($key, 'private'));
        // Private bytes live OUTSIDE the docroot (under the private root), never under public.
        $this->assertFileExists($this->privateRoot . '/' . $key);
        $this->assertFileDoesNotExist($this->publicRoot . '/' . $key);

        $fs->delete($key, 'private');
        $this->assertFalse($fs->exists($key, 'private'));
    }

    #[Test]
    public function delete_is_idempotent_and_get_missing_throws(): void
    {
        $fs = $this->adapter();
        // Deleting a key that was never written is a no-op, never an error.
        $fs->delete('never/written.txt', 'public');
        $this->assertFalse($fs->exists('never/written.txt', 'public'));

        $this->expectException(RuntimeException::class);
        $fs->get('never/written.txt', 'public');
    }

    // ---- Public vs private root split ---------------------------------------------------

    #[Test]
    public function public_url_is_a_docroot_url_private_url_is_empty(): void
    {
        $fs  = $this->adapter();
        $key = 'org-1/images/photo.jpg';

        // Public → a real, directly-servable docroot URL (public_url + the key).
        $this->assertSame(self::PUBLIC_URL . '/' . $key, $fs->url($key, 'public'));
        // Private → '' — the media layer must stream it through the ACL-checked route.
        $this->assertSame('', $fs->url($key, 'private'));
    }

    #[Test]
    public function public_url_does_not_double_slash_a_leading_slash_key(): void
    {
        // url() ltrim()s the key so a stray leading slash can't produce '//' in the URL.
        $this->assertSame(self::PUBLIC_URL . '/a/b.png', $this->adapter()->url('/a/b.png', 'public'));
    }

    // ---- The traversal guard (the P1 security surface) ----------------------------------

    /**
     * Every one of these keys must be REFUSED (they all carry `..`). We assert the throw AND
     * that the escape target file was never created — a guard that threw but still wrote would
     * be a false sense of safety.
     *
     * @return array<string,array{0:string}>
     */
    public static function traversalKeys(): array
    {
        return [
            'parent then sibling'      => ['../OUTSIDE/escaped.txt'],
            'deep climb to /etc'       => ['../../../../../../etc/passwd'],
            'nested mid-path climb'    => ['org-1/images/../../../OUTSIDE/escaped.txt'],
            'backslash-encoded climb'  => ['..\\..\\OUTSIDE\\escaped.txt'],
            'trailing dot-dot'         => ['org-1/..'],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('traversalKeys')]
    public function put_refuses_a_traversal_key_and_writes_nothing_outside_the_root(string $evilKey): void
    {
        $fs      = $this->adapter();
        $src     = $this->sourceFile('malicious');
        $escaped = $this->outside . '/escaped.txt';

        try {
            $fs->put($evilKey, $src, 'private');
            $this->fail("traversal key was accepted: {$evilKey}");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('invalid key', $e->getMessage());
        }
        // The load-bearing assertion: nothing escaped the sandbox root.
        $this->assertFileDoesNotExist($escaped, 'a traversal put wrote outside the configured root');
    }

    #[Test]
    public function write_and_get_and_exists_all_refuse_a_traversal_key(): void
    {
        $fs = $this->adapter();
        // The guard sits in _path(), so it fires on EVERY locating call, read or write.
        foreach (['write', 'get', 'exists', 'size', 'delete'] as $op) {
            try {
                match ($op) {
                    'write'  => $fs->write('../OUTSIDE/x.txt', 'bytes', 'private'),
                    'get'    => $fs->get('../OUTSIDE/x.txt', 'private'),
                    'exists' => $fs->exists('../OUTSIDE/x.txt', 'private'),
                    'size'   => $fs->size('../OUTSIDE/x.txt', 'private'),
                    'delete' => $fs->delete('../OUTSIDE/x.txt', 'private'),
                };
                $this->fail("op '{$op}' accepted a traversal key");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('invalid key', $e->getMessage());
            }
        }
        $this->assertFileDoesNotExist($this->outside . '/x.txt');
    }

    #[Test]
    public function an_empty_key_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->adapter()->write('', 'bytes', 'public');
    }

    #[Test]
    public function a_leading_slash_is_neutralized_and_stays_inside_the_root(): void
    {
        // '/etc/passwd' is NOT a traversal (no '..'); the adapter ltrim()s the slash so it
        // resolves UNDER the root — it must never touch the real absolute /etc/passwd.
        $fs = $this->adapter();
        $fs->write('/etc/passwd', 'not the real one', 'private');

        $this->assertTrue($fs->exists('/etc/passwd', 'private'));
        $this->assertFileExists($this->privateRoot . '/etc/passwd');
        // Sanity: the key with and without the leading slash address the same object.
        $this->assertTrue($fs->exists('etc/passwd', 'private'));
    }

    #[Test]
    public function backslashes_are_normalized_to_forward_slashes(): void
    {
        // A Windows-style separator is folded to '/', so the key nests normally (not a literal
        // 'sub\file.txt' filename) — and, combined with the '..' check, can't be a climb.
        $fs = $this->adapter();
        $fs->write('sub\\dir\\file.txt', 'bytes', 'public');

        $this->assertFileExists($this->publicRoot . '/sub/dir/file.txt');
        $this->assertTrue($fs->exists('sub/dir/file.txt', 'public'));
    }
}
