<?php

namespace Base\Video\Tests\Kernel;

use Base\Video\Entity\Video;
use Base\Video\Search\VideoSearch;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * omnipack/video 3.0 presumes no provider: glitchr/typesense-bundle (the search server) is suggested. Without it
 * the bundle boots and the database answers the search.
 */
final class WithoutSuggestedPackagesTest extends TestCase
{
    private const SUGGESTED = [
        'glitchr/typesense-bundle' => 'Typesense\\Bundle\\',
    ];

    private static function composer(): array
    {
        return json_decode((string) file_get_contents(\dirname(__DIR__, 2).'/composer.json'), true, flags: \JSON_THROW_ON_ERROR);
    }

    public function testTheSearchServerIsSuggestedNotRequired(): void
    {
        $composer = self::composer();
        self::assertArrayNotHasKey('glitchr/typesense-bundle', $composer['require'], 'not required');
        self::assertArrayHasKey('glitchr/typesense-bundle', $composer['suggest'], 'suggested');
        self::assertArrayHasKey('glitchr/typesense-bundle', $composer['require-dev'], 'there for the bundle\'s own suite');
    }

    public function testTheFilmNamesNoClassOfTheSearchServer(): void
    {
        self::assertSame([], array_values(array_filter(class_implements(Video::class), static fn (string $interface) => str_starts_with($interface, 'Typesense\\'))), 'indexed through its mapping, as typesense-bundle 2.0 reads it');
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheBundleBootsWithItsCoreOnly(): void
    {
        if (!class_exists('App\\Kernel')) {
            self::markTestSkipped('Needs a host application (its kernel).');
        }
        WithoutSuggestedPackagesKernel::hide(array_values(self::SUGGESTED));
        $kernel = new WithoutSuggestedPackagesKernel('test', true);
        ob_start(); // omnibase's cache warmers say what they warm
        try {
            $kernel->boot();
        } catch (\Symfony\Component\DependencyInjection\Exception\RuntimeException $e) {
            // The host application's own services may name the search server (its fixtures, its commands): it is not
            // this bundle's to boot without it then.
            if (preg_match('/service "App\\\\[^"]+".*Typesense\\\\/s', $e->getMessage())) {
                self::markTestSkipped('The host application\'s own services need glitchr/typesense-bundle: '.$e->getMessage());
            }
            throw $e;
        } finally {
            ob_end_clean();
        }
        try {
            $container = $kernel->getContainer();
            if (!$container->hasParameter('video.upload.enabled')) {
                self::markTestSkipped('The host application does not load omnipack/video.');
            }
            self::assertFalse(class_exists('Typesense\\Bundle\\EventListener\\TypesenseIndexer'), 'hidden');
            $services = $container->get('test.service_container');
            self::assertFalse($services->has('Base\\Video\\Search\\SafeIndexer'), 'the indexer is the search server\'s');
            $search = $services->get(VideoSearch::class);
            self::assertNull((new \ReflectionProperty($search, 'finder'))->getValue($search), 'no finder: the database answers');
        } finally {
            $kernel->shutdown();
            (new \Symfony\Component\Filesystem\Filesystem())->remove($kernel->getCacheDir());
        }
    }
}
