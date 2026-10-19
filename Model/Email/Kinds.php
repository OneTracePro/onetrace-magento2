<?php

declare(strict_types=1);

namespace OneTrace\Connector\Model\Email;

use Magento\Email\Model\TemplateFactory;
use Magento\Framework\App\Area;
use Magento\Sales\Model\Order;

/**
 * The kind of a store email for OneTrace by the Magento email template: the identifier of a file template, or of
 * the template a custom template (Marketing → Email Templates) was made from. Order kinds can be built from OneTrace
 * templates, the others are relayed as they are.
 */
class Kinds
{
    /** Emails that never go through OneTrace: sign-in and security emails of admin users. */
    public const NATIVE = null;

    /** @var array<string, string> */
    public const TEMPLATES = [
        'sales_email_order_template' => 'order_placed',
        'sales_email_order_guest_template' => 'order_placed',
        'sales_email_invoice_template' => 'order_paid',
        'sales_email_invoice_guest_template' => 'order_paid',
        'sales_email_shipment_template' => 'order_shipped',
        'sales_email_shipment_guest_template' => 'order_shipped',
        'sales_email_creditmemo_template' => 'order_refunded',
        'sales_email_creditmemo_guest_template' => 'order_refunded',
        'sales_email_order_comment_template' => 'order_note',
        'sales_email_order_comment_guest_template' => 'order_note',
        'sales_email_invoice_comment_template' => 'order_note',
        'sales_email_invoice_comment_guest_template' => 'order_note',
        'sales_email_shipment_comment_template' => 'order_note',
        'sales_email_shipment_comment_guest_template' => 'order_note',
        'sales_email_creditmemo_comment_template' => 'order_note',
        'sales_email_creditmemo_comment_guest_template' => 'order_note',
        'sales_email_order_ready_for_pickup_template' => 'order_ready_for_pickup',
        'sales_email_order_ready_for_pickup_guest_template' => 'order_ready_for_pickup',
        // A guest's link to confirm the cancellation of the order: it opens the order.
        'sales_cancellation_confirm_guest' => 'login_link',
        'customer_create_account_email_template' => 'account_new',
        'customer_create_account_email_no_password_template' => 'account_new',
        'customer_create_account_email_confirmed_template' => 'account_new',
        'customer_create_account_email_confirmation_template' => 'email_verification',
        'customer_password_forgot_email_template' => 'password_reset',
        'customer_password_remind_email_template' => 'password_reset',
        // "Your password was changed": a notice without a link.
        'customer_password_reset_password_template' => 'password_changed',
        'customer_account_information_change_email_template' => 'account_changed',
        'customer_account_information_change_email_and_password_template' => 'account_changed',
        'newsletter_subscription_confirm_email_template' => 'newsletter_confirmation',
        'newsletter_subscription_success_email_template' => 'newsletter_subscribed',
        'newsletter_subscription_un_email_template' => 'newsletter_unsubscribed',
        'wishlist_email_email_template' => 'wishlist_shared',
        'sendfriend_email_template' => 'product_shared',
        'catalog_productalert_email_price_template' => 'price_alert',
        'catalog_productalert_email_stock_template' => 'stock_alert',
        // Notifications to the store staff: always sent by Magento (see StoreEmails).
        'contact_email_email_template' => 'admin_contact_form',
        'checkout_payment_failed_template' => 'admin_payment_failed',
        'catalog_productalert_cron_error_email_template' => 'admin_product_alert_error',
        'sitemap_generate_error_email_template' => 'admin_sitemap_error',
        'currency_import_error_email_template' => 'admin_currency_import_error',
    ];

    /** Order emails: the order comes in the template variables. */
    private const BY_ENTITY = ['creditmemo' => 'order_refunded', 'shipment' => 'order_shipped', 'invoice' => 'order_paid', 'order' => 'order_placed'];

    /** @var array<int, string> */
    private array $customTemplates = [];

    public function __construct(private readonly TemplateFactory $templates)
    {
    }

    /**
     * The kind of an email; null — Magento sends it itself (admin user emails: password reset links, two-factor
     * authentication).
     *
     * @param array{identifier: string, vars: array<string, mixed>, options: array<string, mixed>}|null $context
     */
    public function kind(?array $context): ?string
    {
        if ($context === null) {
            return 'other';
        }

        $code = $this->code($context['identifier']);

        if (isset(self::TEMPLATES[$code])) {
            return self::TEMPLATES[$code];
        }

        if (str_starts_with($code, 'admin_') || str_starts_with($code, 'tfa_') || $code === 'user_identity_verify' || ($context['options']['area'] ?? null) === Area::AREA_ADMINHTML) {
            return self::NATIVE;
        }

        // A template of an extension or a custom one without the original: by the order in its variables.
        if (($context['vars']['order'] ?? null) instanceof Order) {
            foreach (self::BY_ENTITY as $variable => $kind) {
                if (is_object($context['vars'][$variable] ?? null)) {
                    return $kind;
                }
            }
        }

        return 'other';
    }

    /**
     * The file template code: custom templates of the admin are numeric ids, made from a file template.
     */
    public function code(string $identifier): string
    {
        if (!ctype_digit($identifier)) {
            return $identifier;
        }

        if (!isset($this->customTemplates[(int) $identifier])) {
            $template = $this->templates->create()->load((int) $identifier);
            $this->customTemplates[(int) $identifier] = (string) $template->getData('orig_template_code');
        }

        return $this->customTemplates[(int) $identifier];
    }
}
