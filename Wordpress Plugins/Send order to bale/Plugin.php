<?php
/**
 * Plugin Name: JALDIO Order File to Bale
 * Description: ارسال فایل سفارش از طریق دکمه المنتور با کلاس send-order به بله، همراه با توضیحات و اطلاعات کاربر.
 * Version: 1.0.0
 * Author: JALDIO
 * Text Domain: jaldio-order-to-bale
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'JALDIO_OTB_VERSION', '1.0.0' );

/**
 * Defaults
 */
function jaldio_otb_defaults() {
    return array(
        'token'   => '1775024543:S1f15sBZ8EXhw7qyMHsBUL4v8sF0uyV9974',
        'chat_id' => '6371259872',
    );
}

function jaldio_otb_get_options() {
    return wp_parse_args(
        get_option( 'jaldio_otb_options', array() ),
        jaldio_otb_defaults()
    );
}

/**
 * Admin settings
 */
add_action( 'admin_menu', function () {
    add_options_page(
        'JALDIO ارسال سفارش به بله',
        'JALDIO بله',
        'manage_options',
        'jaldio-order-to-bale',
        'jaldio_otb_settings_page'
    );
} );

add_action( 'admin_init', function () {
    register_setting(
        'jaldio_otb_group',
        'jaldio_otb_options',
        array(
            'sanitize_callback' => 'jaldio_otb_sanitize_options',
            'default'           => jaldio_otb_defaults(),
        )
    );
} );

function jaldio_otb_sanitize_options( $input ) {
    return array(
        'token'   => isset( $input['token'] ) ? sanitize_text_field( $input['token'] ) : '',
        'chat_id' => isset( $input['chat_id'] ) ? sanitize_text_field( $input['chat_id'] ) : '',
    );
}

function jaldio_otb_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $opts = jaldio_otb_get_options();
    ?>
    <div class="wrap" dir="rtl">
        <h1>ارسال سفارش به بله - JALDIO</h1>
        <p>در المنتور، به دکمه موردنظر کلاس CSS زیر را بدهید:</p>
        <p><code>send-order</code></p>

        <form method="post" action="options.php">
            <?php settings_fields( 'jaldio_otb_group' ); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="jaldio-token">توکن ربات بله</label></th>
                    <td>
                        <input
                            type="text"
                            id="jaldio-token"
                            name="jaldio_otb_options[token]"
                            value="<?php echo esc_attr( $opts['token'] ); ?>"
                            class="regular-text"
                            autocomplete="off"
                        >
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="jaldio-chat-id">Chat ID</label></th>
                    <td>
                        <input
                            type="text"
                            id="jaldio-chat-id"
                            name="jaldio_otb_options[chat_id]"
                            value="<?php echo esc_attr( $opts['chat_id'] ); ?>"
                            class="regular-text"
                            autocomplete="off"
                        >
                    </td>
                </tr>
            </table>

            <?php submit_button( 'ذخیره تنظیمات' ); ?>
        </form>

        <hr>
        <p><strong>فرمت‌های مجاز:</strong> PDF، JPG، JPEG، PNG</p>
        <p><strong>حداکثر حجم:</strong> 10MB</p>
    </div>
    <?php
}

/**
 * AJAX
 */
add_action( 'wp_ajax_jaldio_send_order', 'jaldio_otb_send_order' );
add_action( 'wp_ajax_nopriv_jaldio_send_order', 'jaldio_otb_send_order' );

function jaldio_otb_send_order() {
    if (
        ! isset( $_POST['nonce'] ) ||
        ! wp_verify_nonce(
            sanitize_text_field( wp_unslash( $_POST['nonce'] ) ),
            'jaldio_send_order'
        )
    ) {
        wp_send_json_error( array( 'message' => 'درخواست نامعتبر است.' ), 403 );
    }

    if ( empty( $_FILES['order_file'] ) || ! isset( $_FILES['order_file']['tmp_name'] ) ) {
        wp_send_json_error( array( 'message' => 'لطفاً فایل سفارش را انتخاب کنید.' ) );
    }

    $file = $_FILES['order_file'];

    if ( isset( $file['error'] ) && UPLOAD_ERR_OK !== $file['error'] ) {
        wp_send_json_error( array( 'message' => 'خطا در دریافت فایل.' ) );
    }

    $max_size = 10 * 1024 * 1024;

    if ( ! isset( $file['size'] ) || $file['size'] > $max_size ) {
        wp_send_json_error( array( 'message' => 'حجم فایل نباید بیشتر از 10 مگابایت باشد.' ) );
    }

    $allowed_mimes = array(
        'application/pdf',
        'image/jpeg',
        'image/png',
    );

    $allowed_extensions = array(
        'pdf',
        'jpg',
        'jpeg',
        'png',
    );

    $extension = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

    if ( ! in_array( $extension, $allowed_extensions, true ) ) {
        wp_send_json_error( array( 'message' => 'فرمت فایل مجاز نیست.' ) );
    }

    $file_check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
    $mime_type  = ! empty( $file_check['type'] ) ? $file_check['type'] : '';

    if ( empty( $mime_type ) && function_exists( 'finfo_open' ) ) {
        $finfo = finfo_open( FILEINFO_MIME_TYPE );

        if ( $finfo ) {
            $detected = finfo_file( $finfo, $file['tmp_name'] );
            if ( $detected ) {
                $mime_type = $detected;
            }
            finfo_close( $finfo );
        }
    }

    if ( ! in_array( $mime_type, $allowed_mimes, true ) ) {
        wp_send_json_error(
            array(
                'message' => 'فرمت فایل مجاز نیست. فقط PDF، JPG، JPEG و PNG قابل ارسال هستند.',
            )
        );
    }

    $safe_file_name = sanitize_file_name( $file['name'] );

    if ( empty( $safe_file_name ) ) {
        $safe_file_name = 'order-file.' . $extension;
    }

    $description = '';

    if ( isset( $_POST['order_description'] ) ) {
        $description = sanitize_textarea_field(
            wp_unslash( $_POST['order_description'] )
        );
    }

    $user_name  = 'کاربر مهمان';
    $user_email = '';
    $user_phone = '';

    if ( is_user_logged_in() ) {
        $current_user = wp_get_current_user();

        $full_name = trim(
            $current_user->first_name . ' ' . $current_user->last_name
        );

        if ( empty( $full_name ) && ! empty( $current_user->display_name ) ) {
            $full_name = $current_user->display_name;
        }

        if ( ! empty( $full_name ) ) {
            $user_name = $full_name;
        }

        if ( ! empty( $current_user->user_email ) ) {
            $user_email = $current_user->user_email;
        }

        $user_phone = get_user_meta(
            $current_user->ID,
            'billing_phone',
            true
        );
    }

    $site_name = get_bloginfo( 'name' );

    $caption  = "📦 لیست سفارش جدید\n\n";
    $caption .= "👤 کاربر: " . $user_name . "\n";

    if ( ! empty( $user_email ) ) {
        $caption .= "📧 ایمیل: " . $user_email . "\n";
    }

    if ( ! empty( $user_phone ) ) {
        $caption .= "📱 موبایل: " . $user_phone . "\n";
    }

    $caption .= "🌐 سایت: " . $site_name . "\n";
    $caption .= "📎 فایل: " . $safe_file_name;

    if ( ! empty( $description ) ) {
        $caption .= "\n\n📝 توضیحات:\n" . $description;
    }

    $opts    = jaldio_otb_get_options();
    $token   = trim( $opts['token'] );
    $chat_id = trim( $opts['chat_id'] );

    if ( empty( $token ) || empty( $chat_id ) ) {
        wp_send_json_error(
            array(
                'message' => 'توکن ربات یا Chat ID در تنظیمات افزونه وارد نشده است.',
            )
        );
    }

    if ( ! function_exists( 'curl_init' ) || ! function_exists( 'curl_file_create' ) ) {
        wp_send_json_error(
            array(
                'message' => 'cURL روی هاست فعال نیست.',
            )
        );
    }

    $api_url = 'https://tapi.bale.ai/bot' . rawurlencode( $token ) . '/sendDocument';

    $curl_file = curl_file_create(
        $file['tmp_name'],
        $mime_type,
        $safe_file_name
    );

    $post_fields = array(
        'chat_id'  => $chat_id,
        'caption'  => $caption,
        'document' => $curl_file,
    );

    $ch = curl_init();

    curl_setopt_array(
        $ch,
        array(
            CURLOPT_URL            => $api_url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post_fields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        )
    );

    $response   = curl_exec( $ch );
    $curl_error = curl_error( $ch );
    $http_code  = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

    curl_close( $ch );

    if ( ! empty( $curl_error ) ) {
        wp_send_json_error(
            array(
                'message' => 'ارتباط با سرور بله برقرار نشد.',
            )
        );
    }

    $result = json_decode( $response, true );

    if (
        $http_code < 200 ||
        $http_code >= 300 ||
        empty( $result['ok'] )
    ) {
        $error_message = 'ارسال فایل به بله ناموفق بود.';

        if ( ! empty( $result['description'] ) ) {
            $error_message = sanitize_text_field( $result['description'] );
        }

        wp_send_json_error(
            array(
                'message' => $error_message,
            )
        );
    }

    wp_send_json_success(
        array(
            'message' => 'فایل سفارش با موفقیت ارسال شد.',
        )
    );
}

/**
 * Frontend popup
 */
add_action( 'wp_footer', 'jaldio_otb_order_popup' );

function jaldio_otb_order_popup() {
    if ( is_admin() ) {
        return;
    }
    ?>
    <style>
    #jaldio-order-overlay,
    #jaldio-order-overlay *,
    #jaldio-order-overlay *::before,
    #jaldio-order-overlay *::after{box-sizing:border-box}

    #jaldio-order-overlay{
        position:fixed!important;
        inset:0!important;
        width:100%!important;
        height:100%!important;
        z-index:999999!important;
        display:none;
        align-items:center;
        justify-content:center;
        padding:20px;
        background:rgba(15,23,42,.58);
        backdrop-filter:blur(8px);
        -webkit-backdrop-filter:blur(8px);
        direction:rtl
    }

    #jaldio-order-overlay.active{display:flex!important}

    #jaldio-order-overlay .jaldio-order-modal{
        position:relative!important;
        width:100%!important;
        max-width:580px!important;
        max-height:calc(100vh - 40px);
        margin:0!important;
        padding:34px!important;
        background:#fff!important;
        border-radius:26px!important;
        overflow-y:auto!important;
        overflow-x:hidden!important;
        box-shadow:0 25px 80px rgba(15,23,42,.25)!important;
        direction:rtl;
        text-align:right
    }

    #jaldio-order-overlay .jaldio-order-close{
        position:absolute!important;
        top:18px!important;
        left:18px!important;
        width:40px!important;
        height:40px!important;
        border:0!important;
        border-radius:50%!important;
        background:#f1f5f9!important;
        color:#475569!important;
        font:22px/1 inherit!important;
        display:flex!important;
        align-items:center!important;
        justify-content:center!important;
        cursor:pointer!important
    }

    #jaldio-order-overlay .jaldio-order-icon{
        width:64px!important;
        height:64px!important;
        margin:0 0 20px auto!important;
        border-radius:18px!important;
        background:#f1f5f9!important;
        display:flex!important;
        align-items:center!important;
        justify-content:center!important;
        font-size:28px!important
    }

    #jaldio-order-overlay h3{
        margin:0 0 8px!important;
        color:#0f172a!important;
        font:700 24px/1.6 inherit!important
    }

    #jaldio-order-overlay .jaldio-intro{
        margin:0 0 24px!important;
        color:#64748b!important;
        font:400 13px/2 inherit!important
    }

    #jaldio-order-overlay .jaldio-dropzone{
        display:flex!important;
        flex-direction:column!important;
        align-items:center!important;
        justify-content:center!important;
        width:100%!important;
        min-height:175px!important;
        padding:24px!important;
        border:2px dashed #cbd5e1!important;
        border-radius:18px!important;
        background:#f8fafc!important;
        cursor:pointer!important;
        text-align:center!important
    }

    #jaldio-order-overlay .jaldio-dropzone.dragover,
    #jaldio-order-overlay .jaldio-dropzone:hover{
        background:#f1f5f9!important;
        border-color:#94a3b8!important
    }

    #jaldio-order-overlay .jaldio-upload-icon{
        width:52px!important;
        height:52px!important;
        margin-bottom:12px!important;
        border-radius:14px!important;
        background:#e2e8f0!important;
        display:flex!important;
        align-items:center!important;
        justify-content:center!important;
        font-size:25px!important
    }

    #jaldio-order-overlay .jaldio-dropzone strong{
        margin-bottom:6px!important;
        color:#0f172a!important;
        font-size:14px!important
    }

    #jaldio-order-overlay .jaldio-dropzone span{
        color:#64748b!important;
        font-size:12px!important
    }

    #jaldio-order-overlay .jaldio-file-info{
        display:none;
        align-items:center!important;
        gap:12px!important;
        margin-top:14px!important;
        padding:12px!important;
        border:1px solid #e2e8f0!important;
        border-radius:14px!important;
        background:#f8fafc!important
    }

    #jaldio-order-overlay .jaldio-file-info.active{display:flex!important}

    #jaldio-order-overlay .jaldio-file-type{
        width:44px!important;
        height:44px!important;
        min-width:44px!important;
        border-radius:12px!important;
        background:#e2e8f0!important;
        display:flex!important;
        align-items:center!important;
        justify-content:center!important;
        font-size:20px!important
    }

    #jaldio-order-overlay .jaldio-file-name{
        flex:1!important;
        min-width:0!important;
        color:#0f172a!important;
        font-size:12px!important;
        direction:ltr!important;
        text-align:left!important;
        overflow:hidden!important;
        text-overflow:ellipsis!important;
        white-space:nowrap!important
    }

    #jaldio-order-overlay .jaldio-file-remove{
        border:0!important;
        background:transparent!important;
        color:#ef4444!important;
        font-size:20px!important;
        cursor:pointer!important
    }

    #jaldio-order-overlay .jaldio-description-label{
        display:block!important;
        margin:20px 0 8px!important;
        color:#0f172a!important;
        font-size:13px!important;
        font-weight:700!important
    }

    #jaldio-order-overlay .jaldio-description{
        display:block!important;
        width:100%!important;
        min-height:110px!important;
        padding:13px 14px!important;
        border:1px solid #e2e8f0!important;
        border-radius:14px!important;
        background:#f8fafc!important;
        color:#0f172a!important;
        font:13px/1.9 inherit!important;
        resize:vertical!important;
        outline:none!important
    }

    #jaldio-order-overlay .jaldio-send-file{
        display:flex!important;
        align-items:center!important;
        justify-content:center!important;
        width:100%!important;
        height:52px!important;
        margin-top:18px!important;
        padding:0 20px!important;
        border:0!important;
        border-radius:14px!important;
        background:#0f172a!important;
        color:#fff!important;
        font:700 14px/1 inherit!important;
        cursor:pointer!important
    }

    #jaldio-order-overlay .jaldio-send-file:disabled{
        opacity:.55!important;
        cursor:not-allowed!important
    }

    #jaldio-order-overlay .jaldio-status{
        display:none;
        width:100%!important;
        margin-top:14px!important;
        padding:12px 14px!important;
        border-radius:12px!important;
        font-size:12px!important;
        line-height:1.8!important;
        text-align:center!important
    }

    #jaldio-order-overlay .jaldio-status.active{display:block!important}
    #jaldio-order-overlay .jaldio-status.success{background:#ecfdf5!important;color:#047857!important}
    #jaldio-order-overlay .jaldio-status.error{background:#fef2f2!important;color:#b91c1c!important}

    @media(max-width:600px){
        #jaldio-order-overlay{padding:12px!important}
        #jaldio-order-overlay .jaldio-order-modal{padding:26px 18px!important;border-radius:22px!important}
        #jaldio-order-overlay h3{font-size:20px!important}
        #jaldio-order-overlay .jaldio-dropzone{min-height:155px!important}
    }
    </style>

    <div id="jaldio-order-overlay" aria-hidden="true">
        <div class="jaldio-order-modal" role="dialog" aria-modal="true" aria-labelledby="jaldio-order-title">
            <button type="button" class="jaldio-order-close" aria-label="بستن">×</button>

            <div class="jaldio-order-icon" aria-hidden="true">📦</div>

            <h3 id="jaldio-order-title">ارسال لیست سفارشات</h3>

            <p class="jaldio-intro">
                فایل لیست سفارشات خود را انتخاب کنید تا برای بررسی و پیگیری برای ما ارسال شود.
            </p>

            <form id="jaldio-order-form" enctype="multipart/form-data">
                <input type="hidden" name="action" value="jaldio_send_order">
                <input
                    type="hidden"
                    name="nonce"
                    value="<?php echo esc_attr( wp_create_nonce( 'jaldio_send_order' ) ); ?>"
                >

                <input
                    type="file"
                    id="jaldio-order-file"
                    name="order_file"
                    accept=".pdf,.jpg,.jpeg,.png"
                    hidden
                >

                <label for="jaldio-order-file" class="jaldio-dropzone" id="jaldio-dropzone">
                    <div class="jaldio-upload-icon">📎</div>
                    <strong>فایل را انتخاب کنید</strong>
                    <span>PDF، JPG، JPEG یا PNG — حداکثر 10MB</span>
                </label>

                <div class="jaldio-file-info" id="jaldio-file-info">
                    <div class="jaldio-file-type">📄</div>
                    <div class="jaldio-file-name" id="jaldio-file-name"></div>
                    <button
                        type="button"
                        class="jaldio-file-remove"
                        id="jaldio-file-remove"
                        aria-label="حذف فایل"
                    >×</button>
                </div>

                <label for="jaldio-order-description" class="jaldio-description-label">
                    توضیحات سفارش
                    <span style="font-weight:400;color:#94a3b8">(اختیاری)</span>
                </label>

                <textarea
                    id="jaldio-order-description"
                    name="order_description"
                    class="jaldio-description"
                    maxlength="2000"
                    placeholder="اگر توضیح یا نکته‌ای درباره سفارش دارید، اینجا بنویسید..."
                ></textarea>

                <button
                    type="submit"
                    class="jaldio-send-file"
                    id="jaldio-send-file"
                    disabled
                >ارسال فایل</button>

                <div class="jaldio-status" id="jaldio-status"></div>
            </form>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const overlay = document.getElementById('jaldio-order-overlay');
        const form = document.getElementById('jaldio-order-form');
        const fileInput = document.getElementById('jaldio-order-file');
        const dropzone = document.getElementById('jaldio-dropzone');
        const fileInfo = document.getElementById('jaldio-file-info');
        const fileName = document.getElementById('jaldio-file-name');
        const removeButton = document.getElementById('jaldio-file-remove');
        const sendButton = document.getElementById('jaldio-send-file');
        const status = document.getElementById('jaldio-status');
        const closeButton = overlay.querySelector('.jaldio-order-close');
        const description = document.getElementById('jaldio-order-description');

        if (!overlay || !form || !fileInput) return;

        document.addEventListener('click', function (event) {
            const button = event.target.closest('.send-order');
            if (!button) return;

            event.preventDefault();
            overlay.classList.add('active');
            overlay.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
            clearStatus();
        });

        function closeModal() {
            overlay.classList.remove('active');
            overlay.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }

        closeButton.addEventListener('click', closeModal);

        overlay.addEventListener('click', function (event) {
            if (event.target === overlay) closeModal();
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && overlay.classList.contains('active')) {
                closeModal();
            }
        });

        fileInput.addEventListener('change', function () {
            if (this.files && this.files.length) {
                validateFile(this.files[0]);
            }
        });

        ['dragenter', 'dragover'].forEach(function (eventName) {
            dropzone.addEventListener(eventName, function (event) {
                event.preventDefault();
                event.stopPropagation();
                dropzone.classList.add('dragover');
            });
        });

        ['dragleave', 'drop'].forEach(function (eventName) {
            dropzone.addEventListener(eventName, function (event) {
                event.preventDefault();
                event.stopPropagation();
                dropzone.classList.remove('dragover');
            });
        });

        dropzone.addEventListener('drop', function (event) {
            const files = event.dataTransfer.files;
            if (!files || !files.length) return;

            try {
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(files[0]);
                fileInput.files = dataTransfer.files;
            } catch (e) {}

            validateFile(files[0]);
        });

        function validateFile(file) {
            clearStatus();

            const allowedTypes = [
                'application/pdf',
                'image/jpeg',
                'image/png'
            ];

            const allowedExtensions = [
                'pdf',
                'jpg',
                'jpeg',
                'png'
            ];

            const extension = file.name.split('.').pop().toLowerCase();

            if (
                !allowedTypes.includes(file.type) ||
                !allowedExtensions.includes(extension)
            ) {
                showStatus(
                    'فقط فایل‌های PDF، JPG، JPEG و PNG مجاز هستند.',
                    'error'
                );
                resetFile();
                return;
            }

            const maxSize = 10 * 1024 * 1024;

            if (file.size > maxSize) {
                showStatus(
                    'حجم فایل نباید بیشتر از 10 مگابایت باشد.',
                    'error'
                );
                resetFile();
                return;
            }

            fileName.textContent = file.name + ' — ' + formatSize(file.size);
            fileInfo.classList.add('active');
            sendButton.disabled = false;
        }

        removeButton.addEventListener('click', resetFile);

        function resetFile() {
            fileInput.value = '';
            fileInfo.classList.remove('active');
            fileName.textContent = '';
            sendButton.disabled = true;
        }

        function formatSize(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / 1024 / 1024).toFixed(1) + ' MB';
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            if (!fileInput.files.length) {
                showStatus('لطفاً یک فایل انتخاب کنید.', 'error');
                return;
            }

            sendButton.disabled = true;
            sendButton.textContent = 'در حال ارسال...';
            clearStatus();

            const formData = new FormData(form);

            fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP Error ' + response.status);
                }
                return response.json();
            })
            .then(function (result) {
                if (result.success) {
                    showStatus(
                        result.data.message || 'فایل با موفقیت ارسال شد.',
                        'success'
                    );

                    sendButton.textContent = 'ارسال شد ✓';

                    setTimeout(function () {
                        resetFile();
                        description.value = '';
                        sendButton.textContent = 'ارسال فایل';
                        closeModal();
                    }, 1800);
                } else {
                    let message = 'ارسال فایل ناموفق بود.';

                    if (result.data && result.data.message) {
                        message = result.data.message;
                    }

                    showStatus(message, 'error');
                    sendButton.disabled = false;
                    sendButton.textContent = 'ارسال فایل';
                }
            })
            .catch(function () {
                showStatus(
                    'خطایی هنگام ارسال رخ داد. لطفاً دوباره تلاش کنید.',
                    'error'
                );

                sendButton.disabled = false;
                sendButton.textContent = 'ارسال فایل';
            });
        });

        function showStatus(message, type) {
            status.textContent = message;
            status.className = 'jaldio-status active ' + type;
        }

        function clearStatus() {
            status.textContent = '';
            status.className = 'jaldio-status';
        }
    });
    </script>
    <?php
}
