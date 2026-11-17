<?php

declare(strict_types=1);

namespace MullaPayments\Standalone;

use MullaPayments\Support\Phone;
use MullaPayments\Vendor\Mulla\Enums\Provider;

/**
 * Payments taken with the [mulla_pay] form, stored as a private post type and listed in
 * wp-admin under Mulla → Payments. WooCommerce orders are not stored here.
 */
final class PaymentRecords
{
    public const POST_TYPE = 'mulla_payment';

    public function register(): void
    {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => __('Mulla payments', 'mulla-payments'),
                'singular_name' => __('Mulla payment', 'mulla-payments'),
                'menu_name' => __('Payments', 'mulla-payments'),
                'all_items' => __('Payments', 'mulla-payments'),
                'not_found' => __('No payments yet. Add the [mulla_pay] shortcode or the "Mulla Pay" block to a page.', 'mulla-payments'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'mulla-payments',
            'show_in_rest' => false,
            'supports' => ['title'],
            'capability_type' => 'post',
            'capabilities' => ['create_posts' => 'do_not_allow'],
            'map_meta_cap' => true,
        ]);

        add_filter('manage_' . self::POST_TYPE . '_posts_columns', [$this, 'columns']);
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [$this, 'column'], 10, 2);
    }

    /**
     * Phone and provider are empty for hosted-checkout payments until Mulla reports them.
     *
     * @return int post id
     */
    public function create(int $amount, string $phone, ?Provider $provider, string $reason, string $payer, int $pageId, string $flow): int
    {
        $id = wp_insert_post([
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'post_title' => $reason,
            'meta_input' => [
                '_mulla_status' => 'CREATED',
                '_mulla_flow' => $flow,
                '_mulla_amount' => $amount,
                '_mulla_phone' => $phone,
                '_mulla_provider' => $provider?->value ?? '',
                '_mulla_payer' => $payer,
                '_mulla_page' => $pageId,
                '_mulla_access_token' => wp_generate_password(32, false),
            ],
        ], true);

        if (is_wp_error($id)) {
            throw new \RuntimeException($id->get_error_message());
        }

        return (int) $id;
    }

    public function findByCharge(string $chargeId): ?int
    {
        $ids = get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => 'any',
            'fields' => 'ids',
            'numberposts' => 1,
            'meta_key' => '_mulla_charge_id',
            'meta_value' => $chargeId,
            'no_found_rows' => true,
        ]);

        return isset($ids[0]) ? (int) $ids[0] : null;
    }

    public function get(int $id, string $field): string
    {
        $value = get_post_meta($id, '_mulla_' . $field, true);

        return is_scalar($value) ? (string) $value : '';
    }

    public function set(int $id, string $field, string|int $value): void
    {
        update_post_meta($id, '_mulla_' . $field, $value);
    }

    public function exists(int $id): bool
    {
        return get_post_type($id) === self::POST_TYPE;
    }

    /**
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public function columns(array $columns): array
    {
        return [
            'cb' => $columns['cb'] ?? '',
            'title' => __('Reason', 'mulla-payments'),
            'mulla_amount' => __('Amount', 'mulla-payments'),
            'mulla_status' => __('Status', 'mulla-payments'),
            'mulla_payer' => __('Payer', 'mulla-payments'),
            'mulla_reference' => __('Mulla reference', 'mulla-payments'),
            'date' => $columns['date'] ?? __('Date', 'mulla-payments'),
        ];
    }

    public function column(string $column, int $postId): void
    {
        switch ($column) {
            case 'mulla_amount':
                echo esc_html(number_format_i18n((float) $this->get($postId, 'amount')) . ' RWF');
                break;
            case 'mulla_status':
                $status = $this->get($postId, 'status');
                printf('<mark class="mulla-badge mulla-badge--%s">%s</mark>', esc_attr(strtolower($status)), esc_html(self::statusLabel($status)));
                break;
            case 'mulla_payer':
                $provider = Provider::tryFrom($this->get($postId, 'provider'));
                echo esc_html(trim($this->get($postId, 'payer') . ' ' . $this->get($postId, 'phone')));
                if ($provider !== null) {
                    echo '<br><small>' . esc_html($provider->label()) . '</small>';
                }
                break;
            case 'mulla_reference':
                echo esc_html($this->get($postId, 'reference'));
                break;
        }
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'SUCCESSFUL' => __('Paid', 'mulla-payments'),
            'PENDING' => __('Waiting for approval', 'mulla-payments'),
            'FAILED' => __('Failed', 'mulla-payments'),
            'EXPIRED' => __('Expired', 'mulla-payments'),
            default => __('Not started', 'mulla-payments'),
        };
    }

    /** Masked phone for public responses. */
    public static function maskedPhone(string $phone): string
    {
        return Phone::mask($phone);
    }
}
