<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use DOMDocument;
use DOMXPath;
use Exception;

class UrlPreviewService
{
    private const CACHE_TTL = 86400; // 24 hours
    private const CACHE_PREFIX = 'url_preview:';

    /**
     * Get URL preview with caching
     *
     * @param string $url
     * @return array
     */
    public function getPreview($url)
    {
        $cacheKey = self::CACHE_PREFIX . md5($url);

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($url) {
            return $this->fetchUrlPreview($url);
        });
    }

    /**
     * Get multiple URL previews with caching
     *
     * @param array $urls
     * @return array
     */
    public function getMultiplePreviews($urls)
    {
        $previews = [];

        foreach ($urls as $url) {
            $previews[$url] = $this->getPreview($url);
        }

        return $previews;
    }

    /**
     * Fetch URL preview data
     *
     * @param string $url
     * @return array
     */
    private function fetchUrlPreview($url)
    {
        try {
            // Set timeout and user agent
            $context = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'user_agent' => 'Mozilla/5.0 (WhatsApp-like Bot)',
                    'follow_location' => true,
                    'max_redirects' => 3
                ]
            ]);

            // Get HTML content
            $html = @file_get_contents($url, false, $context);

            if (!$html) {
                return $this->getBasicPreview($url);
            }

            // Parse HTML
            $dom = new DOMDocument();
            libxml_use_internal_errors(true); // Suppress HTML parsing warnings
            @$dom->loadHTML($html);
            libxml_clear_errors();

            $xpath = new DOMXPath($dom);

            // Extract meta data
            $title = $this->extractTitle($xpath, $dom);
            $description = $this->extractDescription($xpath);
            $image = $this->extractImage($xpath, $url);
            $favicon = $this->extractFavicon($xpath, $url);
            $siteName = $this->extractSiteName($xpath, $url);

            return [
                'title' => $title,
                'description' => $description,
                'image' => $image,
                'favicon' => $favicon,
                'site_name' => $siteName,
                'domain' => parse_url($url, PHP_URL_HOST),
                'has_preview' => true,
                'cached_at' => now()->toDateTimeString()
            ];

        } catch (Exception $e) {
            Log::error('URL Preview Error: ' . $e->getMessage() . ' for URL: ' . $url);
            return $this->getBasicPreview($url);
        }
    }

    /**
     * Extract URLs from text
     *
     * @param string $text
     * @return array
     */
    public function extractUrls($text)
    {
        $urls = [];

        // Enhanced URL regex pattern
        $pattern = '/\b(?:(?:https?|ftp):\/\/|www\.|[a-z0-9.-]+\.(?:com|org|net|edu|gov|mil|int|co|io|ly|me|tv|info|biz|name|mobi|tel|travel|jobs|museum|aero|asia|cat|coop|pro|xxx|[a-z]{2}))(?:[^\s<>"\'{}|\\^`\[\]]*[^\s<>"\'{}|\\^`\[\].,;:!?])?/i';

        if (preg_match_all($pattern, $text, $matches)) {
            foreach ($matches[0] as $match) {
                $url = trim($match);

                if (!preg_match('/^https?:\/\//', $url)) {
                    $url = 'http://' . $url;
                }

                if (filter_var($url, FILTER_VALIDATE_URL)) {
                    $urls[] = $url;
                }
            }
        }

        if (filter_var(trim($text), FILTER_VALIDATE_URL)) {
            $urls[] = trim($text);
        }

        return array_unique($urls);
    }

    /**
     * Detect URL type
     *
     * @param string $url
     * @return string
     */
    public function detectUrlType($url)
    {
        $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        $typeMap = [
            'image' => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'ico'],
            'video' => ['mp4', 'avi', 'mov', 'wmv', 'flv', 'webm', 'mkv', '3gp'],
            'audio' => ['mp3', 'wav', 'ogg', 'aac', 'flac', 'm4a'],
            'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'],
            'archive' => ['zip', 'rar', '7z', 'tar', 'gz']
        ];

        foreach ($typeMap as $type => $extensions) {
            if (in_array($extension, $extensions)) {
                return $type;
            }
        }

        // Check for platform-specific URLs
        $domain = parse_url($url, PHP_URL_HOST);
        if ($domain) {
            $platformMap = [
                'youtube_video' => ['youtube.com', 'youtu.be'],
                'vimeo_video' => ['vimeo.com'],
                'instagram' => ['instagram.com'],
                'facebook' => ['facebook.com', 'fb.com'],
                'twitter' => ['twitter.com', 'x.com'],
                'linkedin' => ['linkedin.com']
            ];

            foreach ($platformMap as $type => $domains) {
                foreach ($domains as $platformDomain) {
                    if (strpos($domain, $platformDomain) !== false) {
                        return $type;
                    }
                }
            }
        }

        return 'web_link';
    }

    /**
     * Clear URL preview cache
     *
     * @param string|null $url
     * @return bool
     */
    public function clearCache($url = null)
    {
        if ($url) {
            $cacheKey = self::CACHE_PREFIX . md5($url);
            return Cache::forget($cacheKey);
        }

        // Clear all URL preview caches (use with caution)
        return Cache::flush();
    }

    // Private helper methods...
    private function extractTitle($xpath, $dom)
    {
        $selectors = [
            '//meta[@property="og:title"]/@content',
            '//meta[@name="twitter:title"]/@content'
        ];

        foreach ($selectors as $selector) {
            $result = $xpath->query($selector);
            if ($result->length > 0) {
                return trim($result->item(0)->value);
            }
        }

        $titleNodes = $dom->getElementsByTagName('title');
        if ($titleNodes->length > 0) {
            return trim($titleNodes->item(0)->textContent);
        }

        return null;
    }

    private function extractDescription($xpath)
    {
        $selectors = [
            '//meta[@property="og:description"]/@content',
            '//meta[@name="twitter:description"]/@content',
            '//meta[@name="description"]/@content'
        ];

        foreach ($selectors as $selector) {
            $result = $xpath->query($selector);
            if ($result->length > 0) {
                return trim(substr($result->item(0)->value, 0, 160));
            }
        }

        return null;
    }

    private function extractImage($xpath, $baseUrl)
    {
        $selectors = [
            '//meta[@property="og:image"]/@content',
            '//meta[@name="twitter:image"]/@content'
        ];

        foreach ($selectors as $selector) {
            $result = $xpath->query($selector);
            if ($result->length > 0) {
                return $this->resolveUrl($result->item(0)->value, $baseUrl);
            }
        }

        return null;
    }

    private function extractFavicon($xpath, $baseUrl)
    {
        $selectors = [
            '//link[@rel="icon"]/@href',
            '//link[@rel="shortcut icon"]/@href',
            '//link[@rel="apple-touch-icon"]/@href'
        ];

        foreach ($selectors as $selector) {
            $result = $xpath->query($selector);
            if ($result->length > 0) {
                return $this->resolveUrl($result->item(0)->value, $baseUrl);
            }
        }

        $parsedUrl = parse_url($baseUrl);
        return $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . '/favicon.ico';
    }

    private function extractSiteName($xpath, $url)
    {
        $ogSiteName = $xpath->query('//meta[@property="og:site_name"]/@content');
        if ($ogSiteName->length > 0) {
            return trim($ogSiteName->item(0)->value);
        }

        $domain = parse_url($url, PHP_URL_HOST);
        return str_replace('www.', '', $domain);
    }

    private function resolveUrl($url, $baseUrl)
    {
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }

        $parsedBase = parse_url($baseUrl);
        $scheme = $parsedBase['scheme'];
        $host = $parsedBase['host'];

        if (substr($url, 0, 2) === '//') {
            return $scheme . ':' . $url;
        } elseif (substr($url, 0, 1) === '/') {
            return $scheme . '://' . $host . $url;
        } else {
            return $scheme . '://' . $host . '/' . ltrim($url, '/');
        }
    }

    public function getBasicPreview($url)
    {
        $domain = parse_url($url, PHP_URL_HOST);
        $siteName = str_replace('www.', '', $domain);

        return [
            'title' => $siteName,
            'description' => 'Link to ' . $siteName,
            'image' => null,
            'favicon' => 'https://www.google.com/s2/favicons?domain=' . $domain,
            'site_name' => $siteName,
            'domain' => $domain,
            'has_preview' => false,
            'cached_at' => now()->toDateTimeString()
        ];
    }
}
