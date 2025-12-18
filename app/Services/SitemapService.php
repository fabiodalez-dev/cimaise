<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use Icamys\SitemapGenerator\SitemapGenerator;

/**
 * SitemapService
 * Generates XML sitemap for search engine indexing using icamys/php-sitemap-generator
 */
class SitemapService
{
    private Database $db;
    private string $baseUrl;
    private string $publicPath;

    /**
     * Initialize the SitemapService with its database dependency and site paths.
     *
     * @param Database $db Database instance used to access categories, tags, albums, and settings.
     * @param string $baseUrl Base site URL (trailing slash is optional; internal value is normalized).
     * @param string $publicPath Filesystem path to the public directory (trailing slash is optional; internal value is normalized).
     */
    public function __construct(Database $db, string $baseUrl, string $publicPath)
    {
        $this->db = $db;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->publicPath = rtrim($publicPath, '/');
    }

    /**
     * Generate the site's XML sitemap and update robots.txt with its location.
     *
     * Builds sitemap files in the public path, writes a sitemap index (sitemap.xml),
     * and attempts to append a `Sitemap:` directive to robots.txt if missing.
     *
     * @return array {
     *     Result of the generation operation.
     *
     *     @type bool   $success True when sitemap was generated and written, false on failure.
     *     @type string $message Present when `$success` is true; human-readable success message including sitemap URL.
     *     @type string $file    Present when `$success` is true; filesystem path to the generated sitemap.xml.
     *     @type string $error   Present when `$success` is false; human-readable error message.
     * }
     */
    public function generate(): array
    {
        try {
            $sitemap = new SitemapGenerator($this->baseUrl);

            // Set the path where sitemap files will be saved
            $sitemap->setPath($this->publicPath . '/');

            // Set filename
            $sitemap->setFilename('sitemap');

            // Add homepage (highest priority)
            $sitemap->addURL('/', new \DateTime(), 'daily', 1.0);

            // Add static pages
            $settingsService = new SettingsService($this->db);
            $aboutSlug = (string)($settingsService->get('about.slug', 'about') ?? 'about');

            $sitemap->addURL('/' . $aboutSlug, new \DateTime(), 'monthly', 0.8);
            $sitemap->addURL('/galleries', new \DateTime(), 'weekly', 0.9);

            // Add categories
            $pdo = $this->db->pdo();
            $stmt = $pdo->query('SELECT slug, updated_at FROM categories WHERE slug IS NOT NULL ORDER BY slug');
            $categories = $stmt->fetchAll() ?: [];

            foreach ($categories as $category) {
                $updatedAt = !empty($category['updated_at'])
                    ? new \DateTime($category['updated_at'])
                    : new \DateTime();

                $sitemap->addURL('/category/' . $category['slug'], $updatedAt, 'weekly', 0.7);
            }

            // Add tags
            $stmt = $pdo->query('SELECT slug FROM tags WHERE slug IS NOT NULL ORDER BY slug');
            $tags = $stmt->fetchAll() ?: [];

            foreach ($tags as $tag) {
                $sitemap->addURL('/tag/' . $tag['slug'], new \DateTime(), 'weekly', 0.6);
            }

            // Add published albums (exclude NSFW for privacy/SEO)
            $stmt = $pdo->query('
                SELECT slug, published_at, updated_at, is_nsfw
                FROM albums
                WHERE is_published = 1 AND slug IS NOT NULL
                ORDER BY published_at DESC
            ');
            $albums = $stmt->fetchAll() ?: [];

            foreach ($albums as $album) {
                // Skip NSFW albums (should not be indexed)
                if (!empty($album['is_nsfw'])) {
                    continue;
                }

                $updatedAt = !empty($album['updated_at'])
                    ? new \DateTime($album['updated_at'])
                    : (!empty($album['published_at']) ? new \DateTime($album['published_at']) : new \DateTime());

                $sitemap->addURL('/album/' . $album['slug'], $updatedAt, 'monthly', 0.8);
            }

            // Generate the sitemap files
            $sitemap->createSitemap();

            // Write sitemap index (if multiple sitemap files were created)
            $sitemap->writeSitemap();

            // Update robots.txt to include sitemap reference (if writable)
            $this->updateRobotsTxt();

            return [
                'success' => true,
                'message' => 'Sitemap generated successfully at ' . $this->baseUrl . '/sitemap.xml',
                'file' => $this->publicPath . '/sitemap.xml'
            ];

        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => 'Failed to generate sitemap: ' . $e->getMessage()
            ];
        }
    }

    /**
         * Ensure the site's robots.txt contains a Sitemap directive for the generated sitemap.
         *
         * Reads (or creates) the file at "{publicPath}/robots.txt", checks case-insensitively for an existing
         * "Sitemap:" directive, and if absent appends a "Sitemap: {baseUrl}/sitemap.xml" line. Writing is attempted
         * but failures are suppressed (non-fatal).
         */
    private function updateRobotsTxt(): void
    {
        $robotsPath = $this->publicPath . '/robots.txt';

        // Read existing robots.txt or create default content
        $content = '';
        if (file_exists($robotsPath) && is_readable($robotsPath)) {
            $content = file_get_contents($robotsPath);
            if ($content === false) {
                $content = '';
            }
        }

        // Check if Sitemap directive already exists
        if (stripos($content, 'Sitemap:') === false) {
            // Add sitemap reference
            $sitemapUrl = $this->baseUrl . '/sitemap.xml';
            $content = trim($content) . "\n\nSitemap: " . $sitemapUrl . "\n";

            // Try to write (might fail if not writable, which is OK)
            @file_put_contents($robotsPath, $content);
        }
    }

    /**
     * Determine whether the sitemap XML file exists in the configured public directory.
     *
     * @return bool `true` if sitemap.xml exists in the public path, `false` otherwise.
     */
    public function exists(): bool
    {
        return file_exists($this->publicPath . '/sitemap.xml');
    }

    /**
     * Retrieve the last modification timestamp of the sitemap file.
     *
     * Returns the file modification time of "sitemap.xml" in the public path, or `null` if the file does not exist or its modification time cannot be determined.
     *
     * @return int|null The Unix timestamp of the sitemap's last modification, or `null` when unavailable.
     */
    public function getLastModified(): ?int
    {
        $path = $this->publicPath . '/sitemap.xml';
        if (file_exists($path)) {
            $mtime = filemtime($path);
            return $mtime !== false ? $mtime : null;
        }
        return null;
    }
}