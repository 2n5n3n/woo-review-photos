<?php
/**
 * Plugin Name: Фото в отзывах WooCommerce
 * Description: Позволяет покупателям прикреплять до 5 фотографий к отзывам на товары WooCommerce и просматривать их в галерее.
 * Version: 1.0.0
 * Author: Надежда З.
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: woo-review-photos
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Добавляем поле для загрузки фото в форму отзыва.
 */
add_action( 'comment_form_after_fields', 'wrp_add_photo_field' );
add_action( 'comment_form_logged_in_after', 'wrp_add_photo_field' );

function wrp_add_photo_field() {

    if ( ! is_product() ) {
        return;
    }
    ?>

    <p class="comment-form-photo">
        <label for="review_photo">
            Прикрепить фото (необязательно)
        </label>

        <input
            type="file"
            name="review_photo[]"
            id="review_photo"
            accept="image/jpeg,image/png,image/webp"
            multiple
        >

        <small>
            Можно загрузить до 5 изображений (JPG, PNG, WEBP), до 2 МБ каждое.
        </small>
    </p>

    <?php
}


/**
 * Разрешаем форме комментария отправлять файлы
 * и ограничиваем выбор максимум пятью фотографиями.
 */
add_action( 'wp_footer', 'wrp_add_enctype_script' );

function wrp_add_enctype_script() {

    if ( ! is_product() ) {
        return;
    }
    ?>

    <script>
    document.addEventListener('DOMContentLoaded', function() {

        const form = document.getElementById('commentform');
        const photoInput = document.getElementById('review_photo');

        if (form) {
            form.setAttribute('enctype', 'multipart/form-data');
        }

        if (photoInput) {
            photoInput.addEventListener('change', function() {

                if (this.files.length > 5) {
                    alert('Можно прикрепить не более 5 фотографий.');
                    this.value = '';
                }

            });
        }

    });
    </script>

    <?php
}


/**
 * Обрабатываем загрузку фотографий при отправке отзыва.
 */
add_action( 'comment_post', 'wrp_handle_photo_upload', 10, 2 );

function wrp_handle_photo_upload( $comment_id, $comment_approved ) {

    if (
        empty( $_FILES['review_photo'] )
        || empty( $_FILES['review_photo']['name'] )
    ) {
        return;
    }

    $files = $_FILES['review_photo'];

    $max_files = 5;
    $max_size  = 2 * 1024 * 1024; // 2 МБ

    $allowed_mimes = array(
        'jpg|jpeg' => 'image/jpeg',
        'png'      => 'image/png',
        'webp'     => 'image/webp',
    );

    $attachment_ids = array();

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $count = min( count( $files['name'] ), $max_files );

    for ( $i = 0; $i < $count; $i++ ) {

        if ( $files['error'][ $i ] !== UPLOAD_ERR_OK ) {
            continue;
        }

        if ( $files['size'][ $i ] > $max_size ) {
            continue;
        }

        $single_file = array(
            'name'     => sanitize_file_name( $files['name'][ $i ] ),
            'type'     => $files['type'][ $i ],
            'tmp_name' => $files['tmp_name'][ $i ],
            'error'    => $files['error'][ $i ],
            'size'     => $files['size'][ $i ],
        );

        // Загружаем файл в uploads
        $uploaded = wp_handle_upload(
            $single_file,
            array(
                'test_form' => false,
                'mimes'     => $allowed_mimes,
            )
        );

        if ( isset( $uploaded['error'] ) ) {
            continue;
        }

        // Создаём вложение в медиатеке WordPress
        $attachment = array(
            'post_mime_type' => $uploaded['type'],
            'post_title'     => pathinfo(
                wp_basename( $uploaded['file'] ),
                PATHINFO_FILENAME
            ),
            'post_content'   => '',
            'post_status'    => 'inherit',
        );

        $attachment_id = wp_insert_attachment(
            $attachment,
            $uploaded['file']
        );

        if ( is_wp_error( $attachment_id ) ) {
            continue;
        }

        // Создаём миниатюры WordPress
        $metadata = wp_generate_attachment_metadata(
            $attachment_id,
            $uploaded['file']
        );

        wp_update_attachment_metadata(
            $attachment_id,
            $metadata
        );

        $attachment_ids[] = $attachment_id;
    }

    // Привязываем фотографии к отзыву
    if ( ! empty( $attachment_ids ) ) {
        update_comment_meta(
            $comment_id,
            'review_photo_ids',
            $attachment_ids
        );
    }
}


/**
 * Выводим фотографии в отзыве.
 */
add_filter( 'comment_text', 'wrp_display_photo_in_review', 10, 2 );

function wrp_display_photo_in_review( $comment_text, $comment ) {

    if ( ! is_product() ) {
        return $comment_text;
    }

    $attachment_ids = get_comment_meta(
        $comment->comment_ID,
        'review_photo_ids',
        true
    );

    if ( empty( $attachment_ids ) || ! is_array( $attachment_ids ) ) {
        return $comment_text;
    }

    $html = '<div class="review-photos" data-review-gallery>';

    foreach ( $attachment_ids as $id ) {

        $full = wp_get_attachment_image_url( $id, 'full' );

        if ( ! $full ) {
            continue;
        }

        $thumb = wp_get_attachment_image(
            $id,
            'medium',
            false,
            array(
                'class'   => 'review-photo-thumb',
                'loading' => 'lazy',
            )
        );

        $html .= sprintf(
            '<a href="%s" class="review-photo-link">%s</a>',
            esc_url( $full ),
            $thumb
        );
    }

    $html .= '</div>';

    return $comment_text . $html;
}

add_action( 'wp_footer', 'wrp_review_gallery_lightbox' );

function wrp_review_gallery_lightbox() {

    if ( ! is_product() ) {
        return;
    }
    ?>

    <style>
        .review-photos {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 15px;
        }

        .review-photo-link {
            display: block;
            width: 110px;
            height: 110px;
            overflow: hidden;
            border-radius: 6px;
            cursor: zoom-in;
        }

        .review-photo-thumb {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .wrp-lightbox {
            position: fixed;
            inset: 0;
            z-index: 999999;
            background: rgba(0, 0, 0, 0.9);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 30px 70px;
        }

        .wrp-lightbox.is-open {
            display: flex;
        }

        .wrp-lightbox img {
            max-width: 100%;
            max-height: 90vh;
            width: auto;
            height: auto;
            object-fit: contain;
        }

        .wrp-lightbox-close,
        .wrp-lightbox-prev,
        .wrp-lightbox-next {
            position: absolute;
            border: 0;
            background: rgba(0,0,0,.35);
            color: #fff;
            cursor: pointer;
            font-size: 36px;
            line-height: 1;
            z-index: 2;
        }

        .wrp-lightbox-close {
            top: 20px;
            right: 25px;
            width: 44px;
            height: 44px;
            border-radius: 50%;
        }

        .wrp-lightbox-prev,
        .wrp-lightbox-next {
            top: 50%;
            transform: translateY(-50%);
            width: 50px;
            height: 60px;
            border-radius: 6px;
        }

        .wrp-lightbox-prev {
            left: 15px;
        }

        .wrp-lightbox-next {
            right: 15px;
        }

        @media (max-width: 600px) {
            .review-photo-link {
                width: 90px;
                height: 90px;
            }

            .wrp-lightbox {
                padding: 20px 45px;
            }

            .wrp-lightbox-prev,
            .wrp-lightbox-next {
                width: 40px;
                height: 50px;
                font-size: 28px;
            }
        }
    </style>

    <div class="wrp-lightbox" id="wrp-lightbox">
        <button type="button" class="wrp-lightbox-close" aria-label="Закрыть">×</button>
        <button type="button" class="wrp-lightbox-prev" aria-label="Предыдущее фото">‹</button>

        <img src="" alt="Фото из отзыва">

        <button type="button" class="wrp-lightbox-next" aria-label="Следующее фото">›</button>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {

        const lightbox = document.getElementById('wrp-lightbox');

        if (!lightbox) {
            return;
        }

        const image = lightbox.querySelector('img');
        const close = lightbox.querySelector('.wrp-lightbox-close');
        const prev = lightbox.querySelector('.wrp-lightbox-prev');
        const next = lightbox.querySelector('.wrp-lightbox-next');

        let images = [];
        let currentIndex = 0;

        document.querySelectorAll('[data-review-gallery]').forEach(function(gallery) {

            const links = Array.from(
                gallery.querySelectorAll('.review-photo-link')
            );

            links.forEach(function(link, index) {

                link.addEventListener('click', function(e) {
                    e.preventDefault();

                    images = links.map(function(item) {
                        return item.href;
                    });

                    currentIndex = index;

                    openImage();
                });

            });

        });

        function openImage() {
            if (!images.length) {
                return;
            }

            image.src = images[currentIndex];
            lightbox.classList.add('is-open');
            document.body.style.overflow = 'hidden';

            updateArrows();
        }

        function closeLightbox() {
            lightbox.classList.remove('is-open');
            image.src = '';
            document.body.style.overflow = '';
        }

        function showPrev() {
            if (currentIndex > 0) {
                currentIndex--;
                image.src = images[currentIndex];
                updateArrows();
            }
        }

        function showNext() {
            if (currentIndex < images.length - 1) {
                currentIndex++;
                image.src = images[currentIndex];
                updateArrows();
            }
        }

        function updateArrows() {
            prev.style.display = currentIndex === 0 ? 'none' : '';
            next.style.display =
                currentIndex === images.length - 1 ? 'none' : '';
        }

        close.addEventListener('click', closeLightbox);
        prev.addEventListener('click', showPrev);
        next.addEventListener('click', showNext);

        lightbox.addEventListener('click', function(e) {
            if (e.target === lightbox) {
                closeLightbox();
            }
        });

        document.addEventListener('keydown', function(e) {

            if (!lightbox.classList.contains('is-open')) {
                return;
            }

            if (e.key === 'Escape') {
                closeLightbox();
            }

            if (e.key === 'ArrowLeft') {
                showPrev();
            }

            if (e.key === 'ArrowRight') {
                showNext();
            }

        });

    });
    </script>

    <?php
}