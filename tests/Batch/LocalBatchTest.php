<?php

declare(strict_types=1);

namespace App\Tests\Batch;

use App\Batch\LocalImageSource;
use App\Command\ImportDirectoryCommand;
use App\Entity\GalleryImage;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\{EntityManager, ORMSetup};
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Survos\AiWorkflowBundle\Workflow\SubjectFlow;

final class LocalBatchTest extends TestCase
{
    private string $root;
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aE9sAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/local-batch-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/photos/wheelchair', 0777, true);
        file_put_contents($this->root.'/photos/wheelchair/label.png', base64_decode(self::PNG));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testFlysystemReadsNestedImagesAndSkipsNonImages(): void
    {
        file_put_contents($this->root.'/photos/notes.txt', 'Useful notes');
        $files = iterator_to_array((new LocalImageSource())->images($this->root.'/photos'));
        self::assertCount(1, $files);
        self::assertSame('wheelchair/label.png', $files[0]['path']);
        self::assertSame('image/png', $files[0]['mime']);
        self::assertSame(hash('sha256', base64_decode(self::PNG)), $files[0]['sha256']);
        self::assertSame('data:image/png;base64,'.self::PNG, (new LocalImageSource())->dataUri($this->root.'/photos/wheelchair/label.png'));
    }

    public function testRejectsFakeImage(): void
    {
        file_put_contents($this->root.'/photos/fake.jpg', '<script>no</script>');
        $this->expectException(\RuntimeException::class);
        iterator_to_array((new LocalImageSource())->images($this->root.'/photos'));
    }

    public function testRejectsSymlinksOutsideMount(): void
    {
        file_put_contents($this->root.'/outside.png', base64_decode(self::PNG));
        symlink($this->root.'/outside.png', $this->root.'/photos/outside.png');
        $this->expectException(\League\Flysystem\UnableToListContents::class);
        iterator_to_array((new LocalImageSource())->images($this->root.'/photos'));
    }

    public function testDryRunIdempotenceGroupingAndPrivateSnapshot(): void
    {
        $config = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 2).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);
        $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
        (new SchemaTool($em))->createSchema([$em->getClassMetadata(GalleryImage::class)]);
        $command = new ImportDirectoryCommand($em, new LocalImageSource(), $this->root);
        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
        $command($io, $this->root.'/photos', 'Loan closet', true);
        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM GalleryImage'));
        self::assertDirectoryDoesNotExist($this->root.'/var');
        $command($io, $this->root.'/photos', 'Loan closet');
        $command($io, $this->root.'/photos', 'Loan closet');
        self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM GalleryImage'));
        $image = $em->find(GalleryImage::class, $em->getConnection()->fetchOne('SELECT code FROM GalleryImage'));
        self::assertSame(SubjectFlow::PLACE_PREPARED, $image->marking);
        self::assertSame(1, $image->pendingCount(SubjectFlow::TRANSITION_OBSERVE));
        self::assertSame('wheelchair', $image->legacy['batch']['group']);
        self::assertStringStartsWith($this->root.'/var/batch-images/', $image->localOriginalPath);
        self::assertSame(base64_decode(self::PNG), file_get_contents($image->localOriginalPath));
        // A changed source becomes a new version; it never replaces prior AI evidence.
        file_put_contents($this->root.'/photos/wheelchair/label.png', base64_decode(self::PNG).'changed');
        $command($io, $this->root.'/photos', 'Loan closet');
        self::assertSame(2, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM GalleryImage'));
        self::assertSame(base64_decode(self::PNG), file_get_contents($image->localOriginalPath));
        $em->close();
    }
}
