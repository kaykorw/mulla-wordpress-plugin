<?php
/**
 * Mulla → Settings page. Included from SettingsPage::render(), so $this is the SettingsPage.
 *
 * @var \MullaPayments\Admin\SettingsPage $this
 * @var string $mode
 * @var string $webhookUrl
 * @var bool   $isLocal
 */

use MullaPayments\Settings\Settings;

defined('ABSPATH') || exit;
?>
<div class="wrap mulla-admin">
    <h1><?php esc_html_e('Mulla Payments', 'mulla-payments'); ?></h1>
    <?php $this->renderNotice(); settings_errors(Settings::OPTION); ?>

    <div class="mulla-admin__status">
        <?php if ($this->settings->isConfigured()) : ?>
            <span class="mulla-badge mulla-badge--successful"><?php echo esc_html($mode === 'live' ? __('Live', 'mulla-payments') : __('Test mode', 'mulla-payments')); ?></span>
            <?php esc_html_e('Ready to accept payments.', 'mulla-payments'); ?>
        <?php else : ?>
            <span class="mulla-badge mulla-badge--failed"><?php esc_html_e('Not configured', 'mulla-payments'); ?></span>
            <?php
            /* translators: %s: test or live */
            echo esc_html(sprintf(__('Add your %s secret key below (Mulla dashboard → Developers → API keys).', 'mulla-payments'), $mode));
            ?>
        <?php endif; ?>
    </div>

    <form method="post" action="options.php">
        <?php settings_fields(self::GROUP); ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Environment', 'mulla-payments'); ?></th>
                <td>
                    <?php $this->lockedNote('mode'); ?>
                    <fieldset class="mulla-mode">
                        <legend class="screen-reader-text"><?php esc_html_e('Environment', 'mulla-payments'); ?></legend>
                        <label class="mulla-mode__option"><input type="radio" name="<?php echo esc_attr(Settings::OPTION); ?>[mode]" value="test" <?php checked($mode, 'test'); disabled($this->settings->isLocked('mode')); ?>> <?php echo esc_html(Settings::modeLabel('test')); ?></label>
                        <label class="mulla-mode__option"><input type="radio" name="<?php echo esc_attr(Settings::OPTION); ?>[mode]" value="live" <?php checked($mode, 'live'); disabled($this->settings->isLocked('mode')); ?>> <?php echo esc_html(Settings::modeLabel('live')); ?></label>
                    </fieldset>
                    <p class="description" data-mulla-mode="test"><?php esc_html_e('No real money moves. Use your sk_test_ key.', 'mulla-payments'); ?></p>
                    <p class="description" data-mulla-mode="live"><?php esc_html_e('Customers pay real money. Use your sk_live_ key.', 'mulla-payments'); ?></p>
                </td>
            </tr>
            <?php
            $this->secretRow('test_secret_key', __('Sandbox secret key', 'mulla-payments'), 'sk_test_…');
            $this->secretRow('live_secret_key', __('Production secret key', 'mulla-payments'), 'sk_live_…');
            ?>
            <tr>
                <th scope="row"><?php esc_html_e('Webhook URL', 'mulla-payments'); ?></th>
                <td>
                    <code class="mulla-copy"><?php echo esc_html($webhookUrl); ?></code>
                    <p class="description">
                        <?php esc_html_e('Mulla calls this URL when a payment succeeds or fails. Use "Set up webhook" below to register it automatically.', 'mulla-payments'); ?>
                        <?php if ($isLocal) : ?>
                            <br><strong><?php esc_html_e('This site is not reachable from the internet, so Mulla cannot call it. Payments are still confirmed by polling; use a tunnel (e.g. ngrok) to test webhooks.', 'mulla-payments'); ?></strong>
                        <?php endif; ?>
                    </p>
                </td>
            </tr>
            <?php
            $this->secretRow('test_webhook_secret', __('Sandbox webhook secret', 'mulla-payments'), '');
            $this->secretRow('live_webhook_secret', __('Production webhook secret', 'mulla-payments'), '');
            ?>
            <tr>
                <th scope="row"><?php esc_html_e('Debug log', 'mulla-payments'); ?></th>
                <td><label><input type="checkbox" name="<?php echo esc_attr(Settings::OPTION); ?>[debug]" value="1" <?php checked($this->settings->debug()); ?>> <?php esc_html_e('Log every API request (WooCommerce → Status → Logs, source "mulla-payments"). Secrets are never logged.', 'mulla-payments'); ?></label></td>
            </tr>
            <tr>
                <th scope="row"><label for="mulla-base-url"><?php esc_html_e('Gateway URL (advanced)', 'mulla-payments'); ?></label></th>
                <td>
                    <?php $this->lockedNote('base_url'); ?>
                    <input id="mulla-base-url" class="regular-text" type="url" name="<?php echo esc_attr(Settings::OPTION); ?>[base_url]" value="<?php echo esc_attr($this->settings->get('base_url')); ?>" placeholder="<?php esc_attr_e('Leave empty for the official Mulla gateway', 'mulla-payments'); ?>" <?php disabled($this->settings->isLocked('base_url')); ?>>
                </td>
            </tr>
        </table>
        <?php submit_button(); ?>
    </form>

    <h2><?php esc_html_e('Tools', 'mulla-payments'); ?></h2>
    <p>
        <?php $this->actionButton('mulla_payments_test_connection', __('Test connection', 'mulla-payments')); ?>
        <?php $this->actionButton('mulla_payments_setup_webhook', __('Set up webhook', 'mulla-payments')); ?>
    </p>
    <p class="description"><?php esc_html_e('"Set up webhook" points your Mulla webhook to this site and saves its secret here. Both tools use the saved environment, so save your changes first after switching.', 'mulla-payments'); ?></p>

    <h2><?php esc_html_e('Taking payments', 'mulla-payments'); ?></h2>
    <ul class="ul-disc">
        <?php if (class_exists('WooCommerce')) : ?>
            <li><a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=mulla')); ?>"><?php esc_html_e('Enable Mobile Money in WooCommerce checkout', 'mulla-payments'); ?></a> <?php esc_html_e('(the store currency must be RWF).', 'mulla-payments'); ?></li>
        <?php endif; ?>
        <li><?php echo wp_kses(__('Add a payment form to any page with the <strong>Mulla Pay</strong> block or the shortcode <code>[mulla_pay amount="5000" reason="Membership"]</code>. Leave out <code>amount</code> to let visitors choose.', 'mulla-payments'), ['strong' => [], 'code' => []]); ?></li>
    </ul>
</div>
