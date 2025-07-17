<?php
// config/media-library.php

return [
    /*
     * The disk on which to store added files and derived images by default. Choose
     * one or more of the disks you've configured in config/filesystems.php.
     */
    'disk_name' => env('MEDIA_DISK', 'public'),

    /*
     * The maximum file size of an item in bytes.
     * Adding a larger file will result in an exception.
     */
    'max_file_size' => 1024 * 1024 * 10, // 10MB

    /*
     * This queue will be used to generate derived and responsive images.
     * Leave empty to use the default queue.
     */
    'queue_name' => '',

    /*
     * By default all conversions will be performed on a queue.
     */
    'queue_conversions_by_default' => env('QUEUE_CONVERSIONS_BY_DEFAULT', true),

    /*
     * The fully qualified class name of the media model.
     */
    'media_model' => Spatie\MediaLibrary\MediaCollections\Models\Media::class,

    /*
     * The fully qualified class name of the model used for temporary uploads.
     */
    'temporary_upload_model' => Spatie\MediaLibrary\MediaCollections\Models\Media::class,

    /*
     * When enabled, media collections will be serialised using the media library.
     * This can be useful when using media collections in API responses.
     */
    'use_default_collection_serialization' => false,

    /*
     * The engine that should perform the image conversions.
     * Should be either `gd` or `imagick`.
     */
    'image_driver' => env('IMAGE_DRIVER', 'gd'),

    /*
     * FFMPEG & FFProbe binaries paths, only used if you try to generate video
     * thumbnails and have installed the php-ffmpeg/php-ffmpeg composer package.
     */
    'ffmpeg_path' => env('FFMPEG_PATH', '/usr/bin/ffmpeg'),
    'ffprobe_path' => env('FFPROBE_PATH', '/usr/bin/ffprobe'),

    /*
     * The path where to store temporary files while performing image conversions.
     * If set to null, storage_path('app/temp') will be used.
     */
    'temporary_directory_path' => null,

    /*
     * Here you can override the class names of the jobs used by this package. Make sure
     * your custom jobs extend the ones provided by the package.
     */
    'jobs' => [
        'perform_conversions' => \Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob::class,
        'perform_conversions_queue' => env('MEDIA_QUEUE', 'default'),
    ],

    /*
     * When using the addMediaFromUrl method, this class will be called. Leave empty
     * to use the default downloader.
     */
    'media_downloader' => Spatie\MediaLibrary\Downloaders\DefaultDownloader::class,

    /*
     * This is the class that is responsible for naming generated files.
     */
    'file_namer' => Spatie\MediaLibrary\Support\FileNamer\DefaultFileNamer::class,

    /*
     * The class that contains the strategy for determining a media file's path.
     */
    'path_generator' => App\Support\MediaLibrary\CustomPathGenerator::class,

    /*
     * The class that contains the strategy for determining how to add uuid's to a media file.
     */
    'uuid_generator' => Spatie\MediaLibrary\Support\UuidGenerator\DefaultUuidGenerator::class,

    /*
     * When urls to files get generated, this class will be called. Use the default
     * if your files are stored locally above the site root or on s3.
     */
    'url_generator' => Spatie\MediaLibrary\Support\UrlGenerator\DefaultUrlGenerator::class,

    /*
     * Moves media on updating to keep path consistent. Enable it only with a custom
     * PathGenerator that uses, for example, the media UUID.
     */
    'moves_media_on_update' => false,

    /*
     * Whether to activate versioning when urls to files get generated.
     * When activated, this attaches a ?v=xx query string to the URL.
     */
    'version_urls' => false,

    /*
     * The media library will try to optimize all converted images by removing
     * metadata and applying a little bit of compression. These are
     * the optimizers that will be used by default.
     */
    'image_optimizers' => [
        Spatie\ImageOptimizer\Optimizers\Jpegoptim::class => [
            '-m85',
            '--force',
            '--strip-all',
            '--all-progressive',
        ],

        Spatie\ImageOptimizer\Optimizers\Pngquant::class => [
            '--force',
        ],

        Spatie\ImageOptimizer\Optimizers\Optipng::class => [
            '-i0',
            '-o2',
            '-quiet',
        ],

        Spatie\ImageOptimizer\Optimizers\Svgo::class => [
            '--disable=cleanupIDs',
        ],

        Spatie\ImageOptimizer\Optimizers\Gifsicle::class => [
            '-b',
            '-O3',
        ],

        Spatie\ImageOptimizer\Optimizers\Cwebp::class => [
            '-m', '6',
            '-q', '90',
            '-mt',
            '-f', '80',
        ],

        Spatie\ImageOptimizer\Optimizers\Avifenc::class => [
            '-a', 'cq-level=23',
            '-j', 'all',
            '--min', '0',
            '--max', '63',
            '--minalpha', '0',
            '--maxalpha', '63',
            '-a', 'end-usage=q',
            '-a', 'tune=ssim',
        ],
    ],

    /*
     * These generators will be used to create an image of media files.
     */
    'image_generators' => [
        Spatie\MediaLibrary\Conversions\ImageGenerators\Image::class,
        Spatie\MediaLibrary\Conversions\ImageGenerators\Webp::class,
        Spatie\MediaLibrary\Conversions\ImageGenerators\Pdf::class,
        Spatie\MediaLibrary\Conversions\ImageGenerators\Svg::class,
        Spatie\MediaLibrary\Conversions\ImageGenerators\Video::class,
    ],

    /*
     * The engine that should perform the audio conversions.
     */
    'audio_driver' => env('AUDIO_DRIVER', null),

    /*
     * The engine that should perform the video conversions.
     */
    'video_driver' => env('VIDEO_DRIVER', null),

    /*
     * The generator that should be used for generating thumbnails of videos.
     */
    'video_thumbnail_generator' => null,

    /*
     * The generator that should be used for generating thumbnails of audios.
     */
    'audio_thumbnail_generator' => null,

    /*
     * Here you can specify which jobs should run on which queues.
     * Use an empty string to use the default queue.
     */
    'queue_connection_name' => env('QUEUE_CONNECTION', 'sync'),

    /*
     * The class responsible for generating responsive images.
     */
    'responsive_images' => [

        /*
         * This class is responsible for generating responsive images.
         */
        'width_calculator' => Spatie\MediaLibrary\ResponsiveImages\WidthCalculator\FileSizeOptimizedWidthCalculator::class,

        /*
         * By default, responsive images will be generated when a media object is created.
         * You can control this behaviour by setting this value to `false`.
         */
        'generate_responsive_images' => true,

        /*
         * This class is responsible for generating tiny placeholders that can be
         * used to improve the user experience when responsive images are being loaded.
         */
        'tiny_placeholder_generator' => Spatie\MediaLibrary\ResponsiveImages\TinyPlaceholderGenerator\Blurred::class,
    ],

    /*
     * When generating responsive images, this class will be used to determine
     * which files should be generated.
     */
    'conversion_file_namer' => Spatie\MediaLibrary\Conversions\DefaultConversionFileNamer::class,
];