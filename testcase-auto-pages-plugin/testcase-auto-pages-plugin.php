<?php
/*
Plugin Name: Авто генератор страниц с информацией о матчах
Description: Создает страницы с блоком для информации о матче из данных API
Version: 1.0.1
Author: Ivan Shumilov
*/

if (!defined('ABSPATH')) exit;

//Добавим кастомный тип на init
add_action('init', 'apg_register_custom_post_type', 1);
function apg_register_custom_post_type()
{
    register_post_type('events', [
        'labels' => [
            'name' => 'Матчи',
            'singular_name' => 'Матч',
        ],
        'show_in_menu' => true,
        'menu_position' => 4,
        'public' => true,
        'has_archive' => false,
        'rewrite' => ['slug' => 'event'], // Set the URL prefix to "events"
        'supports' => ['title', 'editor', 'author'],
    ]);
    flush_rewrite_rules();
}

// Хук активации с евентом на обновление данных
function apg_activate()
{
    apg_fetch_match_data();
    if (!wp_next_scheduled('apg_fetch_match_data_shedule')) {
        wp_schedule_single_event(time(), 'daily', 'apg_fetch_match_data_shedule');
    }
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'apg_activate');

// Хук деактивации
function apg_deactivate()
{
    wp_clear_scheduled_hook('apg_fetch_match_data_shedule');
    // Удаляем посты с типом 'event'
    $matches = get_posts([
        'post_type' => 'events',
        'numberposts' => -1,
        'post_status' => 'any',
    ]);
    foreach ($matches as $match) {
        wp_delete_post($match->ID, true);
    }
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'apg_deactivate');

// Получаем данные
function apg_fetch_match_data()
{
    // Получаем данные с API
    $url = 'https://stata.wp-maks.ru/tipsscore/backing.php?type=ru&site=test.fjdsfkle65123.com&ip=91.193.182.154&events_by_season=true&season_id=43865&page=1';
    $response = wp_remote_get($url);

    if (is_wp_error($response)) {
        return;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!$data || !isset($data['data'])) {
        return;
    }

    // Обновляем или создаем страницы
    foreach ($data['data'] as $match) {
        $match_id = $match['id'];
        $post = get_page_by_path("1_{$match_id}", OBJECT, 'events');
        //Отдельный блок для матча
        $match_block = sprintf(
            '<div class="apg-match-data" data-match-id="%s">Кто:<br/>' .
                '<h1>%s — %s</h1>' .
                '<img src="%s" alt="%s"> <img src="%s" alt="%s">' .
                '<p>Где: %s</p>' .
                '<p>Когда: %s</p>' .
                '</div>',
            esc_attr($match_id),
            esc_html($match['home_team']['name']),
            esc_html($match['away_team']['name']),
            esc_url($match['home_team']['logo']),
            esc_attr($match['home_team']['name']),
            esc_url($match['away_team']['logo']),
            esc_attr($match['away_team']['name']),
            esc_html($match['section']['name']),
            esc_html($match['start_at'])
        );

        if ($post) {
            $updated_content = preg_replace('/<div class="apg-match-data".*?>.*?<\/div>/s', $match_block, $post->post_content, 1, $count);
            if ($count === 0) {
                $updated_content = $match_block . $post->post_content;
            }
            wp_update_post([
                'ID' => $post->ID,
                'post_content' => $updated_content,
                'post_type' => 'events',
                'post_title' => "Матч: {$match['home_team']['name']} — {$match['away_team']['name']}"
            ]);
        } else {
            $content = $match_block;
            wp_insert_post([
                'post_title' => "Матч: {$match['home_team']['name']} — {$match['away_team']['name']}",
                'post_name' => "1_{$match_id}",
                'post_content' => $content,
                'post_status' => 'publish',
                'post_type' => 'events',
            ]);
        }
    }
}
add_action('apg_fetch_match_data_shedule', 'apg_fetch_match_data');

// Добавляем пункт в админку
function apg_admin_menu()
{
    add_menu_page('Авто генератор страниц', 'Страницы матчей', 'manage_options', 'apg-settings', 'apg_admin_page');
}
add_action('admin_menu', 'apg_admin_menu');

// Страница настроек с кнопочкой на обновление вручную
function apg_admin_page()
{
    if (isset($_POST['apg_manual_update']) && check_admin_referer('apg_manual_update_action', 'apg_manual_update_nonce')) {
        apg_fetch_match_data();
        echo '<div class="updated"><p>' . esc_html('Данные матчей обновлены.') . '</p></div>';
    }
    echo '<form method="post">';
    wp_nonce_field('apg_manual_update_action', 'apg_manual_update_nonce');
    echo '<button type="submit" name="apg_manual_update" class="button button-primary">Обновить матчи</button>';
    echo '</form>';
}
