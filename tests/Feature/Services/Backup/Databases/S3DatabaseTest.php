<?php

use App\Services\Backup\Databases\S3Database;
use App\Services\Backup\Filesystems\Awss3Filesystem;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use League\Flysystem\Visibility;

function s3DatabaseAdapter(string $dir): Filesystem
{
    return new Filesystem(new LocalFilesystemAdapter(
        $dir,
        new PortableVisibilityConverter(defaultForDirectories: Visibility::PUBLIC)
    ), ['visibility' => Visibility::PUBLIC]);
}

/** Build an S3Database whose getFilesystem() serves the given fixture dir. */
function s3DatabaseOver(string $dir): S3Database
{
    $awss3 = Mockery::mock(Awss3Filesystem::class);
    $awss3->shouldReceive('get')->andReturn(s3DatabaseAdapter($dir));

    return new S3Database($awss3);
}

beforeEach(function () {
    $this->srcDir = sys_get_temp_dir().'/s3database-'.uniqid();
    mkdir($this->srcDir, 0755, true);
});

afterEach(function () {
    if (is_dir($this->srcDir)) {
        \App\Support\FilesystemSupport::cleanupDirectory($this->srcDir);
    }
});

test('folder discovery returns top-level folders and keeps the root "" segment for loose objects', function () {
    // folders + a loose object at the bucket root + a deeply nested child.
    mkdir($this->srcDir.'/customers', 0755, true);
    mkdir($this->srcDir.'/orders/invoices', 0755, true);
    file_put_contents($this->srcDir.'/customers/a.txt', 'c');
    file_put_contents($this->srcDir.'/orders/invoices/b.txt', 'i');
    file_put_contents($this->srcDir.'/readme.txt', 'loose-file-at-root');

    $db = s3DatabaseOver($this->srcDir);
    $db->setConfig(['root' => '', 'bucket' => 'b']);

    // Non-recursive discovery lists folders and root objects only (the nested
    // `orders/invoices` never surfaces as its own segment), and the '' from the
    // root loose object is not dropped by deduplication.
    expect($db->listDatabases())->toEqual(['', 'customers', 'orders']);
});

test('a bucket holding only root-level files still exposes the root scope', function () {
    file_put_contents($this->srcDir.'/readme.txt', 'only-loose');
    file_put_contents($this->srcDir.'/notes.txt', 'more');

    $db = s3DatabaseOver($this->srcDir);
    $db->setConfig(['root' => '', 'bucket' => 'b']);

    expect($db->listDatabases())->toEqual(['']);
});
