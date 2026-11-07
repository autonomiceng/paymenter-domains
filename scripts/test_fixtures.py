"""Select read-only native fixtures that can share guarded Composer dependencies."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
from pathlib import Path


STAFF_READS = {
    'tests/staff-operation-reads-test.php',
    'tests/staff-operation-sync-test.php',
    'tests/staff-operation-read-resume-test.php',
    'tests/staff-operation-renewal-replacements-test.php',
}


def retained_vendor(jobs, requested):
    # Only these bounded serial read-only cases retain pages until final verification.
    # The all-term checkout's full guards reached 65.328s after its assertions passed.
    return jobs == 1 and bool(requested) and set(requested).issubset(
        STAFF_READS | {'tests/offer-bulk-commerce-test.php'}
    )


def shared_vendor(jobs, requested):
    return (
        jobs == 1
        and requested
        and all(
            Path(path).name.startswith("test_installed_")
            or path in STAFF_READS
            or path in {
                # Cancellation regression only reads verified Composer dependencies.
                'tests/native-cancellation-reason-test.php',
                # Renewal fixtures only read dependencies; all source/vendor guards still run.
                'tests/namesilo-legacy-account-guard-test.php',
                'tests/staff-holding-modals-test.php',
                'tests/owner-auto-renew-safety-test.php',
                'tests/owner-auto-renew-calendar-test.php',
                'tests/owner-auto-renew-cycle-test.php',
                'tests/renewal-completion-safety-test.php',
                'tests/renewal-completion-races-test.php',
                'tests/refund-renewal-replacement-test.php',
                'tests/renewal-initialized-fixture-test.php',
                'tests/price-match-test.php',
                'tests/renewal-funds-boundary-test.php',
                'tests/renewal-cycle-late-test.php',
                'tests/renewal-cycle-late-replacement-test.php',
                'tests/renewal-cycle-early-test.php',
                'tests/renewal-cycle-credit-test.php',
                'tests/renewal-complimentary-cycle-test.php',
                'tests/late-renewal-autopay-test.php',
                'tests/late-renewal-deadline-test.php',
                'tests/renewal-namesilo-test.php',
                'tests/renewal-autopay-admission-test.php',
                'tests/renewal-execution-test.php',
                'tests/enom-test-renewal-guards-test.php',
                'tests/late-renewal-test.php',
                'tests/renewal-safety-test.php',
                'tests/renewal-execution-namesilo-test.php',
                'tests/renewal-read-recovery-test.php',
                'tests/refunds-test.php',
                'tests/refund-evidence-test.php',
                'tests/refund-renewals-test.php',
                'tests/refund-renewal-reversals-test.php',
                'tests/refund-retired-receipts-test.php',
                'tests/refund-retired-partial-test.php',
                'tests/refund-renewal-retries-test.php',
                'tests/refund-retired-renewals-test.php',
                'tests/cancelled-renewal-payment-test.php',
                'tests/owner-auto-renew-test.php',
                'tests/runtime-worker-test.php',
                'tests/runtime-wire-registration-test.php',
                'tests/runtime-wire-renewal-test.php',
                'tests/staff-holding-test.php',
                'tests/adopted-renewal-test.php',
                'tests/adopted-renewal-refusals-test.php',
                'tests/adopted-test-environment-test.php',
                'tests/adopted-renewal-history-test.php',
                'tests/adopted-complimentary-test.php',
                'tests/renewal-deadline-test.php',
                'tests/adopted-renewal-deadline-test.php',
                'tests/seller-switch-renewal-test.php',
                'tests/seller-switch-namesilo-renewal-test.php',
                'tests/adoption-consequences-test.php',
                'tests/staff-renewal-release-test.php',
                'tests/offer-frozen-renewal-test.php',
                'tests/namesilo-runtime-renewal-test.php',
                'tests/renewal-completion-test.php',
                'tests/renewal-completion-claims-test.php',
                'tests/registrar-renewal-answer-test.php',
                'tests/reconciliation-namesilo-test.php',
                'tests/management-namesilo-test.php', 'tests/renewal-cycles-test.php',
                'tests/renewal-billing-test.php', 'tests/observation-records-test.php',
                'tests/dns-summary-readback-test.php',
                'tests/observation-namesilo-test.php', 'tests/demo-c-c1-observation-test.php',
                'tests/demo-c-terms-native-test.php', 'tests/enom-multiyear-native-test.php',
                'tests/staff-domain-page-test.php',
                # Staff page/history fixtures read vendor; application and DB state stay isolated.
                'tests/staff-domain-edits-test.php',
                'tests/staff-request-page-test.php', 'tests/staff-review-history-test.php',
                'tests/adoption-customer-test.php',
                'tests/staff-domain-status-test.php',
                'tests/staff-domains-test.php',
                'tests/missing-next-step-test.php',
                'tests/management-progress-test.php',
                # Missing-record recovery uses only guarded, read-only dependencies.
                'tests/management-runtime-recovery-test.php',
                'tests/self-heal-retry-test.php',
                'tests/offer-staff-reads-test.php',
                # Staff catalogue cases isolate app/DB state and only read verified vendor.
                'tests/offer-staff-policy-test.php',
                'tests/staff-catalogue-next-step-test.php',
                'tests/staff-transfer-keep-off-test.php',
                # Sales page cases isolate app/DB state and only read verified vendor.
                'tests/tld-stop-keeps-transfers-test.php',
                'tests/tld-sales-transfers-off-test.php',
                'tests/default-pricing-unreadable-test.php',
                'tests/pricing-editor-notice-test.php',
                'tests/staff-tld-page-test.php',
                'tests/offer-installed-staff-test.php',
                'tests/what-you-sell-render-test.php',
                'tests/staff-tld-render-test.php',
                'tests/offer-activation-test.php',
                'tests/staff-invalid-tld-detail-test.php',
                'tests/staff-hidden-tld-link-test.php',
                'tests/management-runtime-recovery-test.php',
                'tests/staff-sync-worker-test.php',
                # Browser requests mutate only disposable application and SQLite state.
                'tests/test_staff_sync_browser.py',
                'tests/storefront-busy-test.php',
                # Cart ownership witnesses only read vendor; app and SQLite state stay isolated.
                'tests/storefront-cart-owner-test.php',
                'tests/sales-audit-changes-test.php',
                # Checkout uses isolated app/SQLite state and only reads the guarded vendor tree.
                'tests/registration-retail-test.php',
                # All-term commerce only reads vendor; copying it exceeded the file wall cap.
                'tests/offer-bulk-commerce-test.php',
                # These price witnesses isolate app/DB state and only read verified vendor.
                # The writer witness's copied vendor reached the 512 MiB scope cap.
                'tests/offer-writer-test.php',
                'tests/offer-commerce-test.php',
                'tests/invoice-binding-test.php',
                'tests/native-catalogue-test.php',
                'tests/offer-reader-batch-test.php',
                'tests/offer-cutover-test.php',
                'tests/cart-session-price-test.php',
                'tests/checkout-refresh-test.php',
                'tests/cart-price-change-test.php',
                'tests/cart-removal-checkout-test.php',
                'tests/cart-reload-price-test.php',
                'tests/namesilo-management-facts-test.php',
                'tests/test_client_scripts.py', 'tests/lost-reply-status-test.php',
                'tests/namesilo-settings-test.php',
                # These serial witnesses only read the verified vendor tree.
                'tests/namesilo-runtime-read-test.php',
                'tests/namesilo-settings-wording-test.php',
                'tests/native-server-settings-test.php', 'tests/native-server-settings-namesilo-test.php',
                'tests/native-server-recovery-test.php', 'tests/native-server-recovery-namesilo-test.php', 'tests/native-server-lifecycle-guards-test.php',
                'tests/transfer-polling-test.php',
                'tests/namesilo-rebind-test.php',
                'tests/namesilo-enrollment-test.php',
                'tests/staff-domain-edits-namesilo-test.php',
                'tests/binding-test.php',
                'tests/transfer-ceiling-test.php',
                'tests/transfer-dispatch-test.php',
                'tests/transfer-dispatch-namesilo-test.php',
                'tests/transfer-dispatch-refusals-test.php',
                'tests/transfer-completion-test.php',
                'tests/transfer-completion-namesilo-test.php',
                'tests/transfer-completion-namesilo-refusals-test.php',
                'tests/transfer-completion-namesilo-finance-test.php',
                'tests/watch-scheduler-test.php',
                'tests/runtime-records-test.php', 'tests/runtime-records-order-test.php', 'tests/runtime-records-gates-test.php', 'tests/runtime-records-fencing-test.php', 'tests/owner-management-test.php',
                'tests/owner-management-namesilo-test.php', 'tests/staff-monitoring-test.php',
                'tests/namesilo-dns-form-test.php', 'tests/managed-public-observer-test.php',
                'tests/namesilo-dns-delete-form-test.php', 'tests/namesilo-dns-capability-form-test.php',
                'tests/staff-watch-test.php', 'tests/payment-live-ceiling-test.php',
                'tests/staff-monitoring-actions-test.php', 'tests/staff-monitoring-poll-test.php',
                'tests/staff-monitoring-filters-test.php', 'tests/staff-monitoring-templates-test.php',
                'tests/staff-monitoring-expiry-test.php', 'tests/order-payment-test.php', 'tests/staff-domain-hold-status-test.php',
                'tests/registrars-page-test.php', 'tests/registrar-account-page-test.php', 'tests/registrar-limits-page-test.php', 'tests/registrar-activation-page-test.php',
                'tests/namesilo-test-catalogue-test.php',
                'tests/owner-records-test.php',
                'tests/self-heal-records-test.php',
                'tests/self-heal-retry-test.php', 'tests/self-heal-queue-test.php', 'tests/self-heal-recovery-test.php',
                'tests/observation-test.php',
                'tests/offer-scheduler-test.php',
                'tests/storefront-cart-handoff-test.php',
                'tests/site-dns-default-test.php',
                'tests/site-dns-mismatch-test.php',
                'tests/watch-notice-pages-test.php',
                'tests/domain-page-test.php',
                'tests/glossary-sweep-test.php',
                'tests/site-dns-managed-test.php',
                'tests/managed-dns-setup-test.php',
                'tests/managed-public-relink-test.php',
                'tests/owner-actions-test.php',
                # Focused views read verified dependencies; isolate app state, not another vendor copy.
                'tests/owned-pages-test.php', 'tests/owner-renewal-test.php', 'tests/staff-watch-findings-test.php', 'tests/owner-renewal-retained-test.php', 'tests/owner-renewal-integrity-test.php', 'tests/owner-renewal-expiry-test.php', 'tests/owner-renewal-history-test.php', 'tests/owner-renewal-calendar-test.php',
                'tests/registration-enom-test.php',
                'tests/registration-enom-outcomes-test.php',
                'tests/registration-enom-recovery-test.php',
                'tests/registration-enom-dns-test.php',
                'tests/registration-namesilo-test.php',
                'tests/registration-namesilo-authority-test.php',
                'tests/registration-namesilo-nexus-test.php',
                'tests/registration-namesilo-profile-test.php',
                'tests/transfer-inputs-test.php',
                # Entry rendering only reads vendor; keep app and SQLite state isolated.
                'tests/transfer-entry-test.php',
                'tests/storefront-test.php',
                'tests/storefront-transfer-reason-test.php',
                'tests/storefront-corruption-test.php',
                'tests/storefront-queries-test.php',
            }
            for path in requested
        )
    )
