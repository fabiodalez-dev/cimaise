<?php
declare(strict_types=1);

namespace App\Controllers\Admin;
use App\Controllers\BaseController;
use App\Services\SettingsService;
use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class SettingsController extends BaseController
{
    public function __construct(private Database $db, private Twig $view)
    {
        parent::__construct();
    }

    public function show(Request $request, Response $response): Response
    {
        $svc = new SettingsService($this->db);
        $settings = $svc->all();
        
        // Load templates for dropdown
        $templates = [];
        try {
            $templates = $this->db->pdo()->query('SELECT id, name FROM templates ORDER BY name')->fetchAll();
        } catch (\Throwable $e) {
            // Templates table doesn't exist yet
        }
        
        
        return $this->view->render($response, 'admin/settings.twig', [
            'settings' => $settings,
            'templates' => $templates,
            'csrf' => $_SESSION['csrf'] ?? '',
        ]);
    }

    /**
     * Persist settings submitted from the admin settings form and redirect back to the settings page.
     *
     * Validates the CSRF token and, on failure, adds a danger flash and redirects to the settings page.
     * Extracts and normalizes form values (image formats/quality/preview/breakpoints, gallery template and default template ID,
     * site metadata, date format, language, reCAPTCHA keys/enabled, performance options, pagination and cache TTL, lightbox options)
     * and saves them via the SettingsService. Adds a success flash message after saving.
     *
     * @return Response A response that redirects to the admin settings page.
     */
    public function save(Request $request, Response $response): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid CSRF token'];
            return $response->withHeader('Location', $this->redirect('/admin/settings'))->withStatus(302);
        }

        $data = (array)$request->getParsedBody();
        $svc = new SettingsService($this->db);

        // formats
        $formats = [
            'avif' => isset($data['fmt_avif']),
            'webp' => isset($data['fmt_webp']),
            'jpg'  => isset($data['fmt_jpg']),
        ];
        $quality = [
            'avif' => max(1, min(100, (int)($data['q_avif'] ?? 50))),
            'webp' => max(1, min(100, (int)($data['q_webp'] ?? 75))),
            'jpg'  => max(1, min(100, (int)($data['q_jpg'] ?? 85))),
        ];
        $preview = [
            'width' => max(64, (int)($data['preview_w'] ?? 480)),
            'height' => null,
        ];
        
        // breakpoints from textarea JSON
        $breakpoints = json_decode((string)($data['breakpoints'] ?? ''), true);
        if (!is_array($breakpoints)) {
            $breakpoints = $svc->defaults()['image.breakpoints'];
        }
        
        // default template - handle both empty string and actual values
        $defaultTemplateId = null;
        if (isset($data['default_template_id']) && $data['default_template_id'] !== '' && $data['default_template_id'] !== '0') {
            $defaultTemplateId = (int)$data['default_template_id'];
        }
        
        // Site settings
        $siteSettings = [
            'title' => trim((string)($data['site_title'] ?? '')),
            'logo' => ($data['site_logo'] ?? '') !== '' ? (string)$data['site_logo'] : null,
            'description' => trim((string)($data['site_description'] ?? '')),
            'copyright' => trim((string)($data['site_copyright'] ?? '')),
            'email' => trim((string)($data['site_email'] ?? ''))
        ];
        
        // Performance settings  
        $performanceSettings = [
            'compression' => isset($data['enable_compression'])
        ];
        
        $paginationLimit = max(1, min(100, (int)($data['pagination_limit'] ?? 12)));
        $cacheTtl = max(1, min(168, (int)($data['cache_ttl'] ?? 24)));

        // Lightbox settings
        $showExif = isset($data['show_exif_lightbox']);

        // Save all settings
        $svc->set('image.formats', $formats);
        $svc->set('image.quality', $quality);
        $svc->set('image.preview', $preview);
        $svc->set('image.breakpoints', $breakpoints);
        $svc->set('lightbox.show_exif', $showExif);
        
        $galleryPageTemplate = $data['gallery_page_template'] ?? 'classic';
        if (!in_array($galleryPageTemplate, ['classic', 'hero', 'magazine'])) {
            $galleryPageTemplate = 'classic';
        }
        $svc->set('gallery.page_template', $galleryPageTemplate);

        $svc->set('gallery.default_template_id', $defaultTemplateId);
        $svc->set('site.title', $siteSettings['title']);
        $svc->set('site.logo', $siteSettings['logo']);
        $svc->set('site.description', $siteSettings['description']);  
        $svc->set('site.copyright', $siteSettings['copyright']);
        $svc->set('site.email', $siteSettings['email']);

        // Date format setting
        $dateFormat = in_array($data['date_format'] ?? 'Y-m-d', ['Y-m-d', 'd-m-Y'], true)
            ? $data['date_format']
            : 'Y-m-d';
        $svc->set('date.format', $dateFormat);

        // Site language setting
        $siteLanguage = preg_replace('/[^a-z0-9_-]/i', '', (string)($data['site_language'] ?? 'en')) ?: 'en';
        $svc->set('site.language', $siteLanguage);

        // reCAPTCHA settings
        $recaptchaSiteKey = trim((string)($data['recaptcha_site_key'] ?? ''));
        $recaptchaSecretKey = trim((string)($data['recaptcha_secret_key'] ?? ''));
        $recaptchaEnabled = isset($data['recaptcha_enabled']);

        $svc->set('recaptcha.site_key', $recaptchaSiteKey !== '' ? $recaptchaSiteKey : null);
        $svc->set('recaptcha.secret_key', $recaptchaSecretKey !== '' ? $recaptchaSecretKey : null);
        $svc->set('recaptcha.enabled', $recaptchaEnabled);

        $svc->set('performance.compression', $performanceSettings['compression']);
        $svc->set('pagination.limit', $paginationLimit);
        $svc->set('cache.ttl', $cacheTtl);

        $_SESSION['flash'][] = ['type'=>'success','message'=>'Settings saved successfully'];
        return $response->withHeader('Location', $this->redirect('/admin/settings'))->withStatus(302);
    }

    /**
     * Starts background generation of missing image variants and redirects to the settings page.
     *
     * If CSRF validation fails, a danger flash message is added and the user is redirected to /admin/settings.
     * On success, an informational flash message is added indicating the generation has started.
     * On error, a danger flash message containing the error message is added.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request Incoming HTTP request (used for CSRF validation).
     * @param \Psr\Http\Message\ResponseInterface $response HTTP response to modify and return.
     * @return \Psr\Http\Message\ResponseInterface A response that redirects to /admin/settings with a 302 status.
     */
    public function generateImages(Request $request, Response $response): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid CSRF token'];
            return $response->withHeader('Location', $this->redirect('/admin/settings'))->withStatus(302);
        }

        try {
            $consolePath = dirname(__DIR__, 3) . '/bin/console';
            if (!is_executable($consolePath)) {
                throw new \RuntimeException("Console script not executable");
            }

            // Run the command in the background to prevent timeouts
            $cmd = "nohup php $consolePath images:generate --missing > /tmp/image_generation.log 2>&1 &";
            exec($cmd);
            
            $_SESSION['flash'][] = [
                'type' => 'info',
                'message' => 'Image variant generation started. This process may take a few minutes. You will receive a notification when it is complete.'
            ];
            
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = [
                'type' => 'danger', 
                'message' => 'Error starting generation: ' . $e->getMessage()
            ];
        }
        
        return $response->withHeader('Location', $this->redirect('/admin/settings'))->withStatus(302);
    }

    /**
     * Generate favicons from the configured site logo and redirect back to the settings page.
     *
     * Validates CSRF, verifies a logo is configured and exists in the public directory, invokes the FaviconService to create favicons, sets appropriate flash messages for success, warnings (per-file errors), or failures, and always redirects to /admin/settings.
     *
     * @param Request $request The incoming HTTP request.
     * @param Response $response The outgoing HTTP response.
     * @return Response A response that redirects to /admin/settings with a 302 status.
     */
    public function generateFavicons(Request $request, Response $response): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid CSRF token'];
            return $response->withHeader('Location', $this->redirect('/admin/settings'))->withStatus(302);
        }

        try {
            // Get logo path from settings
            $svc = new SettingsService($this->db);
            $logoPath = (string)($svc->get('site.logo', '') ?? '');

            if ($logoPath === '') {
                $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Please upload a logo first before generating favicons'];
                return $response->withHeader('Location', $this->redirect('/admin/settings'))->withStatus(302);
            }

            // Convert relative path to absolute path
            $publicPath = dirname(__DIR__, 3) . '/public';
            $absoluteLogoPath = $publicPath . $logoPath;

            if (!file_exists($absoluteLogoPath)) {
                $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Logo file not found: ' . $logoPath];
                return $response->withHeader('Location', $this->redirect('/admin/settings'))->withStatus(302);
            }

            // Generate favicons
            $faviconService = new \App\Services\FaviconService($publicPath);
            $result = $faviconService->generateFavicons($absoluteLogoPath);

            if ($result['success']) {
                $generatedCount = count($result['generated']);
                $message = "Successfully generated {$generatedCount} favicon file(s): " . implode(', ', $result['generated']);

                if (!empty($result['errors'])) {
                    $message .= '. Errors: ' . implode(', ', $result['errors']);
                    $_SESSION['flash'][] = ['type' => 'warning', 'message' => $message];
                } else {
                    $_SESSION['flash'][] = ['type' => 'success', 'message' => $message];
                }
            } else {
                $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Failed to generate favicons: ' . ($result['error'] ?? 'Unknown error')];
            }

        } catch (\Throwable $e) {
            $_SESSION['flash'][] = [
                'type' => 'danger',
                'message' => 'Error generating favicons: ' . $e->getMessage()
            ];
        }

        return $response->withHeader('Location', $this->redirect('/admin/settings'))->withStatus(302);
    }
}