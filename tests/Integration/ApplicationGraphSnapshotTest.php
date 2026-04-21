<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Bimaaji\Graph\ApplicationGraphGenerator;
use Waaseyaa\Bimaaji\Introspection\Admin\AdminIntrospectionProvider;
use Waaseyaa\Bimaaji\Introspection\Entity\EntityIntrospectionProvider;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Foundation\Kernel\AbstractKernel;

#[CoversClass(ApplicationGraphGenerator::class)]
final class ApplicationGraphSnapshotTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/bimaaji_snapshot_' . uniqid('', true);
        mkdir($this->projectRoot . '/config', 0755, true);
        mkdir($this->projectRoot . '/storage/framework', 0755, true);

        file_put_contents(
            $this->projectRoot . '/config/waaseyaa.php',
            "<?php return ['database' => ':memory:'];",
        );

        file_put_contents(
            $this->projectRoot . '/config/entity-types.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    new \Waaseyaa\Entity\EntityType(
        id: 'snapshot_story',
        label: 'Snapshot Story',
        class: \Waaseyaa\Bimaaji\Tests\Integration\SnapshotStoryEntity::class,
        keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
        fieldDefinitions: [
            'title' => ['type' => 'string', 'label' => 'Title', 'weight' => 0],
            'summary' => ['type' => 'text', 'label' => 'Summary', 'weight' => 1],
        ],
    ),
    new \Waaseyaa\Entity\EntityType(
        id: 'snapshot_note',
        label: 'Snapshot Note',
        class: \Waaseyaa\Bimaaji\Tests\Integration\SnapshotNoteEntity::class,
        keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
        fieldDefinitions: [
            'title' => ['type' => 'string', 'label' => 'Title', 'weight' => 0],
            'body' => ['type' => 'text', 'label' => 'Body', 'weight' => 1],
            'published' => ['type' => 'boolean', 'label' => 'Published', 'weight' => 2],
        ],
    ),
];
PHP,
        );
    }

    protected function tearDown(): void
    {
        $registryProperty = new \ReflectionProperty(ContentEntityBase::class, 'fieldRegistry');
        $registryProperty->setValue(null, null);

        if (!is_dir($this->projectRoot)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectRoot, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->projectRoot);
    }

    #[Test]
    public function graph_shape_matches_committed_snapshot(): void
    {
        $kernel = new class ($this->projectRoot) extends AbstractKernel {
            public function publicBoot(): void
            {
                $this->boot();
            }
        };
        $kernel->publicBoot();

        $generator = new ApplicationGraphGenerator([
            new EntityIntrospectionProvider($kernel->getEntityTypeManager()),
            new AdminIntrospectionProvider($kernel->getEntityTypeManager()),
        ]);
        $actual = json_encode(
            $generator->generate()->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        self::assertIsString($actual);
        $actual .= "\n";

        $snapshotPath = dirname(__DIR__) . '/fixtures/graph-snapshots/minimal-kernel-graph.json';
        $regenerate = getenv('REGENERATE_BIMAAJI_GRAPH_SNAPSHOT') === '1';
        if ($regenerate) {
            file_put_contents($snapshotPath, $actual);
        }

        $expected = file_get_contents($snapshotPath);
        self::assertIsString($expected);

        self::assertSame(
            $expected,
            $actual,
            "Application graph snapshot drifted. If this is intentional, regenerate with REGENERATE_BIMAAJI_GRAPH_SNAPSHOT=1 ./vendor/bin/phpunit --filter ApplicationGraphSnapshotTest; otherwise fix the regression.",
        );
    }
}

final class SnapshotStoryEntity extends ContentEntityBase
{
    public function __construct(array $values = [])
    {
        parent::__construct(
            entityTypeId: 'snapshot_story',
            values: $values,
            entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
        );
    }
}

final class SnapshotNoteEntity extends ContentEntityBase
{
    public function __construct(array $values = [])
    {
        parent::__construct(
            entityTypeId: 'snapshot_note',
            values: $values,
            entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
        );
    }
}
