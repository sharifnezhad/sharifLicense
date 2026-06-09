<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers and renders the WordPress admin panel for Sharif License management.
 */
class Admin
{

    public function __construct()
    {
        add_action('admin_menu', [$this, 'registerAdminMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        add_action('admin_init', [$this, 'handleDeleteRequest']);
        add_action('wp_ajax_sharif_license_save', [$this, 'ajaxSaveLicense']);
    }

    /**
     * Register the top-level admin menu page.
     *
     * @return void
     */
    public function registerAdminMenu(): void
    {
        add_menu_page(
                'sharifLicense',
                'sharifLicense',
                'manage_options',
                'sharif-license',
                [$this, 'renderAdminPage'],
                'dashicons-lock',
                80
        );
    }

    /**
     * Enqueue admin styles and the inline UI script on the plugin's page only.
     *
     * @param string $currentPageHook Hook suffix of the current admin page.
     *
     * @return void
     */
    public function enqueueAdminAssets(string $currentPageHook): void
    {
        if ($currentPageHook !== 'toplevel_page_sharif-license') {
            return;
        }

        wp_enqueue_style(
                'sharif-license-admin',
                SHARIF_LICENSE_URL . 'assets/admin.css',
                [],
                SHARIF_LICENSE_VERSION
        );

        wp_register_script('sharif-license-admin', false, [], SHARIF_LICENSE_VERSION, true);
        wp_enqueue_script('sharif-license-admin');
        wp_localize_script('sharif-license-admin', 'sharifAjax', [
                'url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('sharif_license_ajax'),
        ]);
        wp_localize_script('sharif-license-admin', 'sharifLang', [
                'saving' => sharif_lang('saving'),
                'genericError' => sharif_lang('generic_error'),
                'connectionError' => sharif_lang('connection_error'),
                'noLicenses' => sharif_lang('no_licenses'),
                'edit' => sharif_lang('edit'),
                'delete' => sharif_lang('delete'),
                'confirmDelete' => sharif_lang('confirm_delete'),
        ]);
        wp_add_inline_script('sharif-license-admin', $this->getInlineScript());
    }

    /**
     * Return the inline JavaScript powering AJAX save, IP rows, and the edit modal.
     *
     * @return string Inline JavaScript.
     */
    private function getInlineScript(): string
    {
        return <<<'JS'
        (function () {
            var modal = document.getElementById('sharif-edit-modal');

            /**
             * Build a single IP input row element.
             * @param {string} value Pre-filled IP value.
             * @returns {HTMLElement}
             */
            function createIpRow(value) {
                var row = document.createElement('div');
                row.className = 'sharif-ip-row';
                var input = document.createElement('input');
                input.type = 'text';
                input.name = 'ips[]';
                input.value = value || '';
                input.placeholder = '1.2.3.4';
                input.className = 'regular-text';
                input.dir = 'ltr';
                var removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'button sharif-remove-ip-btn';
                removeBtn.innerHTML = '&#x2715;';
                row.appendChild(input);
                row.appendChild(removeBtn);
                return row;
            }

            // Delegated clicks: add/remove IP rows, open modal, close modal
            document.addEventListener('click', function (e) {
                if (e.target.classList.contains('sharif-add-ip-btn')) {
                    var addContainer = e.target.parentElement.querySelector('.sharif-ip-container');
                    if (addContainer) { addContainer.appendChild(createIpRow('')); }
                }
                if (e.target.classList.contains('sharif-remove-ip-btn')) {
                    var ipContainer = e.target.closest('.sharif-ip-container');
                    if (ipContainer && ipContainer.querySelectorAll('.sharif-ip-row').length > 1) {
                        e.target.closest('.sharif-ip-row').remove();
                    }
                }
                if (e.target.classList.contains('sharif-edit-btn')) {
                    openEditModal(e.target.getAttribute('data-license-id'), null);
                }
                if (e.target.classList.contains('sharif-modal-close') || e.target === modal) {
                    if (modal) { modal.classList.remove('is-open'); }
                }
            });

            /**
             * Set the Jalali date selects of a form from a "YYYY/MM/DD" string.
             * @param {HTMLElement} form
             * @param {string} jalaliDate
             */
            function setDateSelects(form, jalaliDate) {
                var parts = (jalaliDate || '').split('/');
                form.querySelector('[name="jy"]').value = parts[0] ? String(parseInt(parts[0], 10)) : '';
                form.querySelector('[name="jm"]').value = parts[1] ? String(parseInt(parts[1], 10)) : '';
                form.querySelector('[name="jd"]').value = parts[2] ? String(parseInt(parts[2], 10)) : '';
            }

            /**
             * Open the edit modal for a license, optionally overriding with previous input.
             * @param {number|string} id
             * @param {object|null} override {license_key, domain, ips, expired_date}
             */
            function openEditModal(id, override) {
                if (!modal) { return; }
                var data = override || (window.sharifLicenses && window.sharifLicenses[id]) || {};
                var form = modal.querySelector('form');

                form.querySelector('[name="license_id"]').value = id;
                form.querySelector('[name="name"]').value = data.name || '';
                form.querySelector('[name="license_key"]').value = data.license_key || '';
                form.querySelector('[name="domain"]').value = data.domain || '';
                setDateSelects(form, data.expired_date || '');

                var container = form.querySelector('.sharif-ip-container');
                container.innerHTML = '';
                var ips = (data.ips && data.ips.length) ? data.ips : [''];
                ips.forEach(function (ip) { container.appendChild(createIpRow(ip)); });

                clearFormError(form);
                modal.classList.add('is-open');
            }

            /** Toggle a button's loading state. */
            function setLoading(button, isLoading) {
                if (isLoading) {
                    button.dataset.originalText = button.innerHTML;
                    button.disabled = true;
                    button.innerHTML = '<span class="sharif-spinner"></span> ' + window.sharifLang.saving;
                } else {
                    button.disabled = false;
                    if (button.dataset.originalText) { button.innerHTML = button.dataset.originalText; }
                }
            }

            function showFormError(form, message) {
                var box = form.querySelector('.sharif-form-error');
                if (box) { box.textContent = message; box.style.display = 'block'; }
            }

            function clearFormError(form) {
                var box = form.querySelector('.sharif-form-error');
                if (box) { box.textContent = ''; box.style.display = 'none'; }
            }

            function showTopNotice(type, message) {
                var area = document.getElementById('sharif-notice-area');
                if (!area) { return; }
                area.innerHTML = '<div class="notice notice-' + type + ' is-dismissible"><p></p></div>';
                area.querySelector('p').textContent = message;
            }

            /** Build a <td> with text content. */
            function makeCell(text) {
                var td = document.createElement('td');
                td.textContent = text;
                return td;
            }

            /** Rebuild the license table body and the in-memory license map. */
            function rebuildTable(licenses) {
                window.sharifLicenses = {};
                var tbody = document.querySelector('.sharif-licenses-table tbody');
                tbody.innerHTML = '';

                if (!licenses.length) {
                    var emptyRow = document.createElement('tr');
                    var emptyCell = document.createElement('td');
                    emptyCell.colSpan = 7;
                    emptyCell.textContent = window.sharifLang.noLicenses;
                    emptyRow.appendChild(emptyCell);
                    tbody.appendChild(emptyRow);
                    return;
                }

                licenses.forEach(function (license) {
                    window.sharifLicenses[license.id] = license;

                    var row = document.createElement('tr');
                    row.appendChild(makeCell(license.id));
                    row.appendChild(makeCell(license.name));

                    var keyCell = document.createElement('td');
                    var code = document.createElement('code');
                    code.textContent = license.license_key;
                    keyCell.appendChild(code);
                    row.appendChild(keyCell);

                    row.appendChild(makeCell(license.domain));

                    var ipCell = document.createElement('td');
                    license.ips.forEach(function (ip) {
                        var badge = document.createElement('span');
                        badge.className = 'sharif-ip-badge';
                        badge.textContent = ip;
                        ipCell.appendChild(badge);
                    });
                    row.appendChild(ipCell);

                    var dateCell = makeCell(license.expired_date);
                    dateCell.dir = 'ltr';
                    row.appendChild(dateCell);

                    var actionCell = document.createElement('td');
                    var editBtn = document.createElement('button');
                    editBtn.type = 'button';
                    editBtn.className = 'button button-small sharif-edit-btn';
                    editBtn.setAttribute('data-license-id', license.id);
                    editBtn.textContent = window.sharifLang.edit;
                    actionCell.appendChild(editBtn);
                    actionCell.appendChild(document.createTextNode(' '));
                    var deleteLink = document.createElement('a');
                    deleteLink.href = license.delete_url;
                    deleteLink.className = 'button button-small button-link-delete';
                    deleteLink.textContent = window.sharifLang.delete;
                    deleteLink.addEventListener('click', function (e) {
                        if (!confirm(window.sharifLang.confirmDelete)) { e.preventDefault(); }
                    });
                    actionCell.appendChild(deleteLink);
                    row.appendChild(actionCell);

                    tbody.appendChild(row);
                });
            }

            /** Reset the add form to its empty state. */
            function resetAddForm(form) {
                form.reset();
                form.querySelector('[name="license_id"]').value = '';
                var container = form.querySelector('.sharif-ip-container');
                container.innerHTML = '';
                container.appendChild(createIpRow(''));
            }

            /** Attach AJAX submit handling to a license form. */
            function attachAjaxSubmit(form) {
                form.addEventListener('submit', function (e) {
                    e.preventDefault();
                    var submitButton = form.querySelector('button[type="submit"]');
                    var formData = new FormData(form);
                    formData.append('action', 'sharif_license_save');
                    formData.append('nonce', window.sharifAjax.nonce);

                    setLoading(submitButton, true);
                    clearFormError(form);

                    fetch(window.sharifAjax.url, {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    })
                        .then(function (response) { return response.json(); })
                        .then(function (result) {
                            setLoading(submitButton, false);
                            if (result.success) {
                                rebuildTable(result.data.licenses);
                                showTopNotice('success', result.data.message);
                                if (form.dataset.mode === 'edit') {
                                    modal.classList.remove('is-open');
                                } else {
                                    resetAddForm(form);
                                }
                            } else {
                                var msg = (result.data && result.data.message) ? result.data.message : window.sharifLang.genericError;
                                showFormError(form, msg);
                            }
                        })
                        .catch(function () {
                            setLoading(submitButton, false);
                            showFormError(form, window.sharifLang.connectionError);
                        });
                });
            }

            document.querySelectorAll('.sharif-license-form').forEach(attachAjaxSubmit);
        })();
        JS;
    }

    /**
     * Handle license deletion (normal GET link with nonce).
     *
     * @return void
     */
    public function handleDeleteRequest(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (!isset($_GET['action'], $_GET['license_id']) || $_GET['action'] !== 'delete') {
            return;
        }
        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'sharif_license_delete')) {
            wp_die(sharif_lang('delete_nonce_failed'));
        }

        $licenseId = (int)$_GET['license_id'];
        $deleteResult = Database::deleteLicense($licenseId);

        if ($deleteResult) {
            $this->setAdminNotice('success', sharif_lang('license_deleted'));
        } else {
            $this->setAdminNotice('error', sharif_lang('license_delete_failed'));
        }

        wp_redirect(admin_url('admin.php?page=sharif-license'));
        exit;
    }

    /**
     * AJAX handler that creates or updates a license and returns the refreshed list.
     *
     * @return void
     */
    public function ajaxSaveLicense(): void
    {
        check_ajax_referer('sharif_license_ajax', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => sharif_lang('unauthorized')]);
        }

        $licenseId = (int)($_POST['license_id'] ?? 0);
        $fields = $this->readSubmittedFields();

        $validationError = $this->validateLicenseFields($fields, $licenseId ?: null);
        if ($validationError) {
            wp_send_json_error(['message' => $validationError]);
        }

        $gregorianDate = sharif_jalali_string_to_gregorian($fields['expired_date']);

        if ($licenseId) {
            $saveResult = Database::updateLicense(
                    $licenseId,
                    $fields['name'],
                    $fields['license_key'],
                    $fields['domain'],
                    $fields['ips'],
                    $gregorianDate
            );
            $successMessage = sharif_lang('license_updated');
        } else {
            $saveResult = Database::insertLicense(
                    $fields['name'],
                    $fields['license_key'],
                    $fields['domain'],
                    $fields['ips'],
                    $gregorianDate
            );
            $successMessage = sharif_lang('license_added');
        }

        if (!$saveResult) {
            wp_send_json_error(['message' => sharif_lang('save_failed')]);
        }

        wp_send_json_success([
                'message' => $successMessage,
                'licenses' => $this->buildLicensePayload(),
        ]);
    }

    /**
     * Read, sanitize and normalize submitted license fields from $_POST.
     *
     * The Jalali date is assembled from the year/month/day select inputs (jy, jm, jd).
     *
     * @return array{name:string,license_key:string,domain:string,ips:string[],expired_date:string}
     */
    private function readSubmittedFields(): array
    {
        $rawIps = isset($_POST['ips']) && is_array($_POST['ips']) ? $_POST['ips'] : [];

        $jalaliYear = (int)sharif_normalize_digits((string)($_POST['jy'] ?? ''));
        $jalaliMonth = (int)sharif_normalize_digits((string)($_POST['jm'] ?? ''));
        $jalaliDay = (int)sharif_normalize_digits((string)($_POST['jd'] ?? ''));

        $jalaliDate = '';
        if ($jalaliYear && $jalaliMonth && $jalaliDay) {
            $jalaliDate = sprintf('%04d/%02d/%02d', $jalaliYear, $jalaliMonth, $jalaliDay);
        }

        return [
                'name' => sanitize_text_field($_POST['name'] ?? ''),
                'license_key' => sanitize_text_field($_POST['license_key'] ?? ''),
                'domain' => sanitize_text_field($_POST['domain'] ?? ''),
                'ips' => $this->sanitizeIpList($rawIps),
                'expired_date' => $jalaliDate,
        ];
    }

    /**
     * Validate all license fields and return the first error message, or null if valid.
     *
     * @param array $fields Sanitized fields from readSubmittedFields().
     * @param int|null $excludeLicenseId License ID to exclude from uniqueness checks (for edit).
     *
     * @return string|null Error message, or null when everything is valid.
     */
    private function validateLicenseFields(array $fields, ?int $excludeLicenseId): ?string
    {
        $name = $fields['name'];
        $licenseKey = $fields['license_key'];
        $domain = $fields['domain'];
        $ips = $fields['ips'];
        $jalaliDate = $fields['expired_date'];

        if (empty($name)) {
            return sharif_lang('name_required');
        }

        if (empty($licenseKey)) {
            return sharif_lang('license_key_required');
        }
        if (!Database::isValidLicenseKey($licenseKey)) {
            return sharif_lang('license_key_invalid');
        }

        if (empty($domain)) {
            return sharif_lang('domain_required');
        }
        if (!Database::isValidDomain($domain)) {
            return sharif_lang('domain_invalid');
        }
        if (Database::isDomainTaken($domain, $excludeLicenseId)) {
            return sharif_lang('domain_taken');
        }

        if (empty($ips)) {
            return sharif_lang('ip_required');
        }
        if (count($ips) !== count(array_unique($ips))) {
            return sharif_lang('ip_duplicate_input');
        }
        foreach ($ips as $ipAddress) {
            if (!Database::isValidIp($ipAddress)) {
                return sprintf(sharif_lang('ip_invalid'), $ipAddress);
            }
            if (Database::findLicenseIdByIp($ipAddress, $excludeLicenseId)) {
                return sprintf(sharif_lang('ip_taken'), $ipAddress);
            }
        }

        if (empty($jalaliDate)) {
            return sharif_lang('date_required');
        }
        if (sharif_jalali_string_to_gregorian($jalaliDate) === null) {
            return sharif_lang('date_invalid');
        }

        return null;
    }

    /**
     * Sanitize and filter the IP list from form submission, dropping empty entries.
     *
     * @param string[] $rawIps Raw IP values from $_POST.
     *
     * @return string[] Cleaned IP addresses.
     */
    private function sanitizeIpList(array $rawIps): array
    {
        $cleanIps = [];
        foreach ($rawIps as $rawIp) {
            $trimmedIp = trim(sanitize_text_field($rawIp));
            if ($trimmedIp !== '') {
                $cleanIps[] = $trimmedIp;
            }
        }
        return $cleanIps;
    }

    /**
     * Store an admin notice in a short-lived transient for display after redirect.
     *
     * @param string $type Notice type: 'success' or 'error'.
     * @param string $message Notice message text.
     *
     * @return void
     */
    private function setAdminNotice(string $type, string $message): void
    {
        set_transient('sharif_license_notice', ['type' => $type, 'message' => $message], 30);
    }

    /**
     * Build the license payload used by both the initial JS data and AJAX responses.
     *
     * @return array<int,array> List of licenses with decrypted fields, Jalali date and delete URL.
     */
    private function buildLicensePayload(): array
    {
        $allLicenses = Database::getAllLicenses();
        $payload = [];

        foreach ($allLicenses as $licenseRecord) {
            $payload[] = [
                    'id' => (int)$licenseRecord->id,
                    'name' => $licenseRecord->name,
                    'license_key' => $licenseRecord->license_key,
                    'domain' => $licenseRecord->domain,
                    'ips' => $licenseRecord->ip_list,
                    'expired_date' => sharif_gregorian_string_to_jalali($licenseRecord->expired_date),
                    'delete_url' => wp_nonce_url(
                            admin_url('admin.php?page=sharif-license&action=delete&license_id=' . $licenseRecord->id),
                            'sharif_license_delete'
                    ),
            ];
        }

        return $payload;
    }

    /**
     * Render the admin page: keys, verify URL, add form, license table and edit modal.
     *
     * @return void
     */
    public function renderAdminPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $secretKey = Database::getSecretKey();
        $verifyUrl = home_url('/api/validate');
        $licenses = $this->buildLicensePayload();
        $adminNotice = get_transient('sharif_license_notice');
        delete_transient('sharif_license_notice');

        ?>
        <div class="wrap sharif-license-wrap">
            <h1>sharifLicense</h1>

            <div id="sharif-notice-area"></div>
            <?php $this->renderNotice($adminNotice); ?>

            <div class="sharif-info-box">
                <div class="sharif-info-row">
                    <strong><?php echo esc_html(sharif_lang('verify_url_label')); ?></strong>
                    <code id="sharif-verify-url"><?php echo esc_html($verifyUrl); ?></code>
                    <button type="button" class="button button-small"
                            onclick="navigator.clipboard.writeText(document.getElementById('sharif-verify-url').innerText)">
                        <?php echo esc_html(sharif_lang('copy')); ?>
                    </button>
                </div>
                <div class="sharif-info-row">
                    <strong><?php echo esc_html(sharif_lang('secret_key_label')); ?></strong>
                    <code id="sharif-secret-key"><?php echo esc_html($secretKey); ?></code>
                    <button type="button" class="button button-small"
                            onclick="navigator.clipboard.writeText(document.getElementById('sharif-secret-key').innerText)">
                        <?php echo esc_html(sharif_lang('copy')); ?>
                    </button>
                </div>
            </div>

            <h2><?php echo esc_html(sharif_lang('add_license_heading')); ?></h2>
            <form method="post" class="sharif-license-form" data-mode="add">
                <?php $this->renderLicenseFormFields(); ?>
                <p class="submit">
                    <button type="submit" class="button button-primary"><?php echo esc_html(sharif_lang('add_license_btn')); ?></button>
                </p>
            </form>

            <h2><?php echo esc_html(sharif_lang('existing_licenses')); ?></h2>
            <table class="wp-list-table widefat fixed striped sharif-licenses-table">
                <thead>
                <tr>
                    <th scope="col" style="width:40px">#</th>
                    <th scope="col"><?php echo esc_html(sharif_lang('col_name')); ?></th>
                    <th scope="col">License Key</th>
                    <th scope="col">Domain</th>
                    <th scope="col"><?php echo esc_html(sharif_lang('col_ips')); ?></th>
                    <th scope="col" style="width:110px"><?php echo esc_html(sharif_lang('col_expire_date')); ?></th>
                    <th scope="col" style="width:140px"><?php echo esc_html(sharif_lang('col_actions')); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($licenses)) : ?>
                    <tr>
                        <td colspan="7"><?php echo esc_html(sharif_lang('no_licenses')); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($licenses as $license) : ?>
                        <tr>
                            <td><?php echo esc_html($license['id']); ?></td>
                            <td><?php echo esc_html($license['name']); ?></td>
                            <td><code><?php echo esc_html($license['license_key']); ?></code></td>
                            <td><?php echo esc_html($license['domain']); ?></td>
                            <td>
                                <?php foreach ($license['ips'] as $ipAddress) : ?>
                                    <span class="sharif-ip-badge"><?php echo esc_html($ipAddress); ?></span>
                                <?php endforeach; ?>
                            </td>
                            <td dir="ltr"><?php echo esc_html($license['expired_date']); ?></td>
                            <td>
                                <button type="button" class="button button-small sharif-edit-btn"
                                        data-license-id="<?php echo esc_attr($license['id']); ?>"><?php echo esc_html(sharif_lang('edit')); ?>
                                </button>
                                <a href="<?php echo esc_url($license['delete_url']); ?>"
                                   onclick="return confirm('<?php echo esc_js(sharif_lang('confirm_delete')); ?>')"
                                   class="button button-small button-link-delete"><?php echo esc_html(sharif_lang('delete')); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php $this->renderEditModal(); ?>

        <script>
            window.sharifLicenses = {};
            (<?php echo wp_json_encode($licenses); ?>).forEach(function (l) {
                window.sharifLicenses[l.id] = l;
            });
        </script>
        <?php
    }

    /**
     * Render the edit modal markup (fields are populated by JavaScript).
     *
     * @return void
     */
    private function renderEditModal(): void
    {
        ?>
        <div id="sharif-edit-modal" class="sharif-modal-overlay">
            <div class="sharif-modal">
                <div class="sharif-modal-header">
                    <h2><?php echo esc_html(sharif_lang('edit_license_heading')); ?></h2>
                    <button type="button" class="sharif-modal-close" aria-label="<?php echo esc_attr(sharif_lang('close')); ?>">&times;</button>
                </div>
                <form method="post" class="sharif-license-form sharif-modal-body" data-mode="edit">
                    <?php $this->renderLicenseFormFields(); ?>
                    <p class="submit">
                        <button type="submit" class="button button-primary"><?php echo esc_html(sharif_lang('save_changes')); ?></button>
                        <button type="button" class="button sharif-modal-close"><?php echo esc_html(sharif_lang('cancel')); ?></button>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Render the shared form fields: hidden id, error box, license key, domain, IPs, Jalali date.
     *
     * Field values are left empty here; the add form starts blank and the modal is
     * populated by JavaScript. AJAX keeps user input on validation errors.
     *
     * @return void
     */
    private function renderLicenseFormFields(): void
    {
        ?>
        <input type="hidden" name="license_id" value=""/>
        <div class="sharif-form-error" style="display:none"></div>
        <table class="form-table">
            <tr>
                <th><label><?php echo esc_html(sharif_lang('name_label')); ?></label></th>
                <td><input type="text" name="name" class="regular-text" required/></td>
            </tr>
            <tr>
                <th><label>License Key</label></th>
                <td><input type="text" name="license_key" class="regular-text" dir="ltr" required/></td>
            </tr>
            <tr>
                <th><label>Domain</label></th>
                <td>
                    <input type="text" name="domain" class="regular-text" dir="ltr" placeholder="example.com" required/>
                    <p class="description"><?php echo esc_html(sharif_lang('domain_hint')); ?></p>
                </td>
            </tr>
            <tr>
                <th><label>IP Addresses</label></th>
                <td>
                    <div class="sharif-ip-container">
                        <div class="sharif-ip-row">
                            <input type="text" name="ips[]" placeholder="1.2.3.4" class="regular-text" dir="ltr"/>
                            <button type="button" class="button sharif-remove-ip-btn">&#x2715;</button>
                        </div>
                    </div>
                    <button type="button" class="button sharif-add-ip-btn" style="margin-top:6px"><?php echo esc_html(sharif_lang('add_ip_btn')); ?></button>
                    <p class="description"><?php echo esc_html(sharif_lang('ip_hint')); ?></p>
                </td>
            </tr>
            <tr>
                <th><label><?php echo esc_html(sharif_lang('expire_date_label')); ?></label></th>
                <td><?php $this->renderJalaliDateSelects(); ?></td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render the Jalali date picker as year / month / day select dropdowns.
     *
     * @return void
     */
    private function renderJalaliDateSelects(): void
    {
        $currentJalaliDate = sharif_gregorian_string_to_jalali(date('Y-m-d'));
        $currentJalaliYear = (int)explode('/', $currentJalaliDate)[0];

        ?>
        <span class="sharif-date-selects" dir="ltr">
            <select name="jy" required>
                <option value=""><?php echo esc_html(sharif_lang('select_year')); ?></option>
                <?php for ($year = $currentJalaliYear; $year <= $currentJalaliYear + 10; $year++) : ?>
                    <option value="<?php echo esc_attr($year); ?>"><?php echo esc_html($year); ?></option>
                <?php endfor; ?>
            </select>
            <select name="jm" required>
                <option value=""><?php echo esc_html(sharif_lang('select_month')); ?></option>
                <?php foreach (sharif_jalali_months() as $monthNumber => $monthName) : ?>
                    <option value="<?php echo esc_attr($monthNumber); ?>"><?php echo esc_html($monthName); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="jd" required>
                <option value=""><?php echo esc_html(sharif_lang('select_day')); ?></option>
                <?php for ($day = 1; $day <= 31; $day++) : ?>
                    <option value="<?php echo esc_attr($day); ?>"><?php echo esc_html($day); ?></option>
                <?php endfor; ?>
            </select>
        </span>
        <?php
    }

    /**
     * Render an admin notice div if a notice array is provided.
     *
     * @param array|false $adminNotice Notice array with 'type' and 'message', or false.
     *
     * @return void
     */
    private function renderNotice($adminNotice): void
    {
        if (!$adminNotice) {
            return;
        }
        ?>
        <div class="notice notice-<?php echo esc_attr($adminNotice['type']); ?> is-dismissible">
            <p><?php echo esc_html($adminNotice['message']); ?></p>
        </div>
        <?php
    }
}
