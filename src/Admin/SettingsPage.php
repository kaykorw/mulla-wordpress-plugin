<?php

declare(strict_types=1);

namespace MullaPayments\Admin;

use MullaPayments\ClientFactory;
use MullaPayments\Settings\Settings;
use MullaPayments\Vendor\Mulla\Exceptions\MullaException;
use MullaPayments\Vendor\Mulla\Exceptions\NotFoundException;
use MullaPayments\Webhooks\WebhookController;

/**
 * Mulla → Settings: API keys per mode, webhook secret, and one-click "Test connection" /
 * "Set up webhook" actions so merchants never have to copy URLs or secrets by hand.
 */
final class SettingsPage
{
    public const SLUG = 'mulla-payments';
    private const GROUP = 'mulla_payments';
    private const SECRETS = ['test_secret_key', 'live_secret_key', 'test_webhook_secret', 'live_webhook_secret'];

    public function __construct(
        private readonly string $file,
        private readonly Settings $settings,
        private readonly ClientFactory $clients,
    ) {}

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'registerSetting']);
        add_action('admin_post_mulla_payments_test_connection', [$this, 'testConnection']);
        add_action('admin_post_mulla_payments_setup_webhook', [$this, 'setupWebhook']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_filter('plugin_action_links_' . plugin_basename($this->file), [$this, 'actionLinks']);
    }

    public function menu(): void
    {
        add_menu_page(__('Mulla', 'mulla-payments'), __('Mulla', 'mulla-payments'), 'manage_options', self::SLUG, [$this, 'render'], 'dashicons-money-alt', 56);
        add_submenu_page(self::SLUG, __('Mulla settings', 'mulla-payments'), __('Settings', 'mulla-payments'), 'manage_options', self::SLUG, [$this, 'render']);
    }

    public function registerSetting(): void
    {
        register_setting(self::GROUP, Settings::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize'],
            'show_in_rest' => false,
        ]);
    }

    /**
     * Blank secret fields keep the saved value; keys are checked against the expected prefix.
     *
     * @param mixed $input
     * @return array<string, string>
     */
    public function sanitize($input): array
    {
        $input = is_array($input) ? $input : [];
        $saved = get_option(Settings::OPTION, []);
        $saved = is_array($saved) ? $saved : [];
        $clean = [];

        $clean['mode'] = ($input['mode'] ?? 'test') === 'live' ? 'live' : 'test';
        $clean['debug'] = !empty($input['debug']) ? 'yes' : 'no';

        foreach (self::SECRETS as $key) {
            $value = trim(sanitize_text_field(wp_unslash((string) ($input[$key] ?? ''))));
            $previous = is_string($saved[$key] ?? null) ? $saved[$key] : '';

            if (!empty($input['clear_' . $key])) {
                $clean[$key] = '';
                continue;
            }
            if ($value === '') {
                $clean[$key] = $previous;
                continue;
            }

            $prefix = str_ends_with($key, '_secret_key') ? 'sk_' . strtok($key, '_') . '_' : null;
            if ($prefix !== null && !str_starts_with($value, $prefix)) {
                add_settings_error(Settings::OPTION, $key, sprintf(
                    /* translators: %s: expected key prefix */
                    __('That key was not saved: it must start with %s. The public key (pk_) is not a secret key.', 'mulla-payments'),
                    $prefix,
                ));
                $clean[$key] = $previous;
                continue;
            }
            $clean[$key] = $value;
        }

        $url = trim((string) ($input['base_url'] ?? ''));
        $clean['base_url'] = $url === '' ? '' : esc_url_raw($url, ['https', 'http']);

        $this->clients->reset();

        return $clean;
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $mode = $this->settings->mode();
        $webhookUrl = WebhookController::url();
        $isLocal = (bool) preg_match('#^https?://(localhost|127\.0\.0\.1|[^/]+\.(test|local))[:/]#', $webhookUrl);
        require dirname($this->file) . '/views/settings-page.php';
    }

    public function testConnection(): void
    {
        $this->guard('mulla_payments_test_connection');

        try {
            $client = $this->clients->client();
            $total = $client->charges->list(['limit' => 1])->total;
            $this->notice('success', sprintf(
                /* translators: 1: test or live, 2: number of charges */
                __('Connected to Mulla in %1$s mode. Your account has %2$d charge(s).', 'mulla-payments'),
                $this->settings->mode(),
                $total,
            ));
        } catch (MullaException $e) {
            $this->notice('error', __('Connection failed:', 'mulla-payments') . ' ' . $e->getMessage());
        }

        $this->back();
    }

    public function setupWebhook(): void
    {
        $this->guard('mulla_payments_setup_webhook');
        $url = WebhookController::url();

        try {
            $webhooks = $this->clients->client()->webhookEndpoint;
            try {
                $webhooks->retrieve();
                $endpoint = $webhooks->update(['url' => $url, 'is_active' => true]);
                if ($this->settings->webhookSecret() === '') {
                    $endpoint = $webhooks->rotateSecret();
                }
            } catch (NotFoundException) {
                $endpoint = $webhooks->create($url);
            }

            if ($endpoint->secret !== null && $endpoint->secret !== '' && !$this->settings->isLocked($this->settings->mode() . '_webhook_secret')) {
                $stored = get_option(Settings::OPTION, []);
                $stored = is_array($stored) ? $stored : [];
                $stored[$this->settings->mode() . '_webhook_secret'] = $endpoint->secret;
                update_option(Settings::OPTION, $stored); // runs sanitize(), which keeps the new secret
            }

            /* translators: %s: webhook URL */
            $this->notice('success', sprintf(__('Webhook set up: Mulla will notify %s.', 'mulla-payments'), $url));
        } catch (MullaException $e) {
            $this->notice('error', __('Could not set up the webhook:', 'mulla-payments') . ' ' . $e->getMessage());
        }

        $this->back();
    }

    public function assets(string $hook): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (str_contains($hook, self::SLUG) || ($screen !== null && $screen->post_type === 'mulla_payment')) {
            wp_enqueue_style('mulla-payments-admin', plugins_url('assets/css/mulla.css', $this->file), [], MULLA_PAYMENTS_VERSION);
        }
    }

    /**
     * @param array<string, string> $links
     * @return array<string, string>
     */
    public function actionLinks(array $links): array
    {
        return ['settings' => '<a href="' . esc_url(admin_url('admin.php?page=' . self::SLUG)) . '">' . esc_html__('Settings', 'mulla-payments') . '</a>'] + $links;
    }

    /** @internal Used by views/settings-page.php. */
    public function secretRow(string $key, string $label, string $placeholder): void
    {
        $value = $this->settings->get($key);
        $locked = $this->settings->isLocked($key);
        $name = Settings::OPTION . '[' . $key . ']';
        ?>
        <tr>
            <th scope="row"><label for="mulla-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
            <td>
                <?php if ($locked) : ?>
                    <?php $this->lockedNote($key); ?>
                <?php else : ?>
                    <input id="mulla-<?php echo esc_attr($key); ?>" class="regular-text" type="password" autocomplete="new-password" name="<?php echo esc_attr($name); ?>" value="" placeholder="<?php echo esc_attr($value !== '' ? '•••••••• ' . substr($value, -4) : $placeholder); ?>">
                    <?php if ($value !== '') : ?>
                        <label class="mulla-clear"><input type="checkbox" name="<?php echo esc_attr(Settings::OPTION . '[clear_' . $key . ']'); ?>" value="1"> <?php esc_html_e('Remove', 'mulla-payments'); ?></label>
                        <p class="description"><?php esc_html_e('Saved. Leave empty to keep it.', 'mulla-payments'); ?></p>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /** @internal Used by views/settings-page.php. */
    public function lockedNote(string $key): void
    {
        if ($this->settings->isLocked($key)) {
            echo '<p class="description">' . esc_html__('Set in wp-config.php.', 'mulla-payments') . '</p>';
        }
    }

    /** @internal Used by views/settings-page.php. */
    public function actionButton(string $action, string $label): void
    {
        printf(
            '<a class="button" href="%s">%s</a> ',
            esc_url(wp_nonce_url(admin_url('admin-post.php?action=' . $action), $action)),
            esc_html($label),
        );
    }

    private function guard(string $action): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', 'mulla-payments'), 403);
        }
        check_admin_referer($action);
    }

    private function notice(string $type, string $message): void
    {
        set_transient('mulla_payments_notice_' . get_current_user_id(), ['type' => $type, 'message' => $message], 60);
    }

    /** @internal Used by views/settings-page.php. */
    public function renderNotice(): void
    {
        $key = 'mulla_payments_notice_' . get_current_user_id();
        $notice = get_transient($key);
        if (!is_array($notice)) {
            return;
        }
        delete_transient($key);
        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr($notice['type'] === 'success' ? 'success' : 'error'),
            esc_html((string) $notice['message']),
        );
    }

    private function back(): void
    {
        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG));
        exit;
    }
}
